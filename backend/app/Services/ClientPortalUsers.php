<?php

namespace App\Services;

use App\Mail\PortalInviteMail;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Inventor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A client's portal logins, shared by the New Client form, the client panel's Users tab
 * and the Client Portal page. Access is fixed by the person's designation at the client:
 * Manager → client_admin (everything, manages the team), CFO and Accounts Person →
 * client_finance (invoices and payments only), CTO and Other → client (cases, documents,
 * invoices), Inventor → an inventor record under the client with an inventor login (own cases).
 * A client login is linked through client_contacts (by email); an inventor through inventors.user_id.
 */
class ClientPortalUsers
{
    public const DESIGNATIONS = ['Manager', 'CFO', 'CTO', 'Accounts Person', 'Inventor', 'Other'];

    /** Firm roles that may create, reset and remove portal logins (same as the Client Portal page). */
    public const MANAGE_ROLES = ['super_admin', 'partner', 'manager', 'hr'];

    public const ROLE_FOR = [
        'Manager'         => 'client_admin',
        'CFO'             => 'client_finance',
        'Accounts Person' => 'client_finance',
        'CTO'             => 'client',
        'Other'           => 'client',
        'Inventor'        => 'inventor',
    ];

    public const ACCESS_LABEL = [
        'client_admin'   => 'Admin',
        'client'         => 'Standard',
        'client_finance' => 'Finance',
        'inventor'       => 'Inventor',
    ];

    /** Invite emails are paused until an admin turns them on (Client Portal page). */
    public const EMAILS_PAUSED_KEY = 'portal_emails_paused';

    public static function emailsPaused(): bool
    {
        $value = DB::table('system_settings')->where('key', self::EMAILS_PAUSED_KEY)->value('value');

        return $value === null ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setEmailsPaused(bool $paused): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => self::EMAILS_PAUSED_KEY], ['value' => $paused ? '1' : '0']);
    }

    /**
     * The Form 1 particulars sent with an Inventor login ($data['inventor']), validated with the
     * same rules as the Inventors tab. Errors are keyed under $prefix (e.g. "inventor.city").
     *
     * @return array<string, mixed>
     */
    public static function inventorParticulars(array $data, string $prefix = 'inventor'): array
    {
        $particulars = is_array($data['inventor'] ?? null) ? $data['inventor'] : [];
        $particulars = array_intersect_key($particulars, Inventor::particularRules(true));
        unset($particulars['email'], $particulars['phone']);   // the login's own email and phone are used
        $rules = array_diff_key(Inventor::particularRules(true, $particulars), ['email' => 1, 'phone' => 1]);

        $validator = Validator::make($particulars, $rules, [
            'address_house.required_without'  => 'Enter the house no. / building or the street / area.',
            'address_street.required_without' => 'Enter the house no. / building or the street / area.',
            'state.required'                  => 'Enter the state (needed for an Indian address).',
        ], [
            'pincode' => 'pin code', 'address_house' => 'house no. / building', 'address_street' => 'street / area',
        ]);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $field => $errors) {
                $messages["{$prefix}.{$field}"] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }

        $clean = $validator->validated();
        foreach ($clean as $field => $value) {
            $clean[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        return $clean;
    }

    /** @return array<int, array<string, mixed>> primary first, then by name */
    public function list(Client $client): array
    {
        $contacts = ClientContact::where('client_id', $client->id)->get(['email', 'designation', 'phone'])
            ->keyBy(fn (ClientContact $c) => strtolower($c->email));

        // Logins already linked to one of this client's inventor records (an inventor login, or a
        // client user such as a CTO who was also picked as an inventor).
        $inventorUserIds = Inventor::where('client_id', $client->id)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $users = User::whereIn('role', User::CLIENT_ROLES)
            ->where(function ($q) use ($contacts, $client) {
                $q->whereIn(DB::raw('LOWER(email)'), $contacts->keys()->all() ?: ['']);
                if ($client->portal_user_id) {
                    $q->orWhere('id', $client->portal_user_id);
                }
            })
            ->get(['id', 'name', 'email', 'role', 'status', 'created_at', 'client_designation_id'])
            ->map(function (User $user) use ($contacts, $client, $inventorUserIds) {
                $contact = $contacts->get(strtolower($user->email));

                return $this->row($user, $contact?->designation, $contact?->phone, (int) $client->portal_user_id === $user->id)
                    + ['is_inventor' => in_array($user->id, $inventorUserIds, true)];
            });

        $listed = $users->pluck('id')->all();
        $inventors = Inventor::where('client_id', $client->id)->whereNotNull('user_id')->with('user:id,name,email,role,status,created_at,client_designation_id')->get()
            ->filter(fn (Inventor $inventor) => $inventor->user && ! in_array($inventor->user->id, $listed, true))
            ->map(fn (Inventor $inventor) => $this->row($inventor->user, 'Inventor', $inventor->phone, false) + ['is_inventor' => true]);

        return $users->concat($inventors)
            ->sortBy(fn (array $row) => [$row['is_primary'] ? 0 : 1, strtolower($row['name'])])
            ->values()->all();
    }

    /**
     * Creates one portal login for the client. $data: name, email, password, designation, phone.
     * Runs in a transaction (joins the caller's); the invite email goes out after commit unless paused.
     */
    public function add(Client $client, array $data, User $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        // The designation (ready-made or the client's own) and its access checkboxes decide the role.
        $designationRow = self::designationFor($client, $data);
        $designation = $designationRow->name;
        $role = \App\Support\ClientAccess::roleFor((array) $designationRow->permissions, (bool) $designationRow->is_inventor);
        $email = trim((string) $data['email']);
        $password = (string) $data['password'];
        $name = trim((string) $data['name']);
        $phone = filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null;
        // An inventor login is also the inventor record Form 1 prints, so its address is required.
        $particulars = $role === 'inventor' ? self::inventorParticulars($data) : [];

        if (User::whereRaw('LOWER(email) = ?', [strtolower($email)])->exists()) {
            throw ValidationException::withMessages(['email' => "{$email} already has a login."]);
        }
        $contact = ClientContact::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if ($contact && (int) $contact->client_id !== (int) $client->id) {
            throw ValidationException::withMessages(['email' => "{$email} is a contact of another client."]);
        }

        $user = DB::transaction(function () use ($client, $actor, $designation, $designationRow, $role, $email, $password, $name, $phone, $contact, $particulars, $ip, $userAgent) {
            $user = User::create([
                'name' => $name, 'email' => $email, 'password' => Hash::make($password),
                'role' => $role, 'status' => 'Active', 'mobile' => $phone,
            ]);
            $user->client_designation_id = $designationRow->id;
            $user->save();

            if ($role === 'inventor') {
                // Listed in the client's Inventors tab, ready to be picked on cases and Form 1.
                Inventor::create($particulars + [
                    'client_id' => $client->id, 'full_name' => $name, 'email' => $email, 'phone' => $phone,
                    'user_id' => $user->id, 'created_by_id' => $actor->id,
                ]);
            } else {
                $fields = ['client_id' => $client->id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'designation' => $designation, 'title' => $designation];
                $contact ? $contact->update($fields) : ClientContact::create($fields);
            }

            $client->portal_enabled = true;
            $client->portal_invited_at = $client->portal_invited_at ?? now();
            // The primary login is the client's Manager (Admin); until there is one, the first login.
            $primaryRole = $client->portal_user_id ? User::whereKey($client->portal_user_id)->value('role') : null;
            if ($role !== 'inventor' && (! $primaryRole || ($role === 'client_admin' && $primaryRole !== 'client_admin'))) {
                $client->portal_user_id = $user->id;
            }
            $client->save();

            AuditLog::create([
                'user_id'      => $actor->id,
                'action'       => 'portal_user_added',
                'subject_type' => 'Client',
                'subject_id'   => $client->id,
                'metadata'     => ['user_id' => $user->id, 'email' => $email, 'designation' => $designation, 'role' => $role, 'client_id' => $client->id],
                'ip_address'   => $ip,
                'user_agent'   => $userAgent,
            ]);

            return $user;
        });

        self::sendInvite($client, $user, $password);

        return $this->row($user, $designation, $phone, (int) $client->portal_user_id === $user->id);
    }

    /** The portal invite email, after commit, unless invite emails are paused (Client Portal page). */
    public static function sendInvite(Client $client, User $user, string $password): void
    {
        if (self::emailsPaused()) {
            return;
        }
        $clientName = $client->company_name ?? $client->legal_name ?? 'Client';
        DB::afterCommit(function () use ($user, $password, $clientName) {
            try {
                Mail::to($user->email)->send(new PortalInviteMail($clientName, $user->email, $password, url('/login')));
            } catch (\Throwable $e) {
                Log::warning('Portal invite email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /** True when the user is one of this client's portal logins (a contact login or one of its inventors). */
    public function belongsTo(Client $client, User $user): bool
    {
        if ($user->role === 'inventor') {
            return Inventor::where('client_id', $client->id)->where('user_id', $user->id)->exists();
        }

        return (int) $client->portal_user_id === $user->id
            || ClientContact::where('client_id', $client->id)->whereRaw('LOWER(email) = ?', [strtolower($user->email)])->exists();
    }

    /** Removes a login (not the primary). An inventor keeps their inventor record, without a login. */
    public function remove(Client $client, User $user, User $actor, ?string $ip = null): void
    {
        if ((int) $client->portal_user_id === $user->id) {
            throw ValidationException::withMessages(['user' => 'The primary portal user cannot be removed. Disable the portal instead.']);
        }
        if (! $this->belongsTo($client, $user)) {
            throw ValidationException::withMessages(['user' => 'This user is not linked to this client.']);
        }

        DB::transaction(function () use ($client, $user, $actor, $ip) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            if ($user->role === 'inventor') {
                Inventor::where('client_id', $client->id)->where('user_id', $user->id)->update(['user_id' => null]);
            } else {
                ClientContact::where('client_id', $client->id)->whereRaw('LOWER(email) = ?', [strtolower($user->email)])->delete();
            }
            AuditLog::create([
                'user_id' => $actor->id, 'action' => 'portal_user_removed', 'subject_type' => 'Client', 'subject_id' => $client->id,
                'metadata' => ['user_id' => $user->id, 'email' => $user->email], 'ip_address' => $ip,
            ]);
            $user->delete();
        });
    }

    /** The designation picked in a form: by id (ready-made or this client's own) or by ready-made name. */
    public static function designationFor(Client $client, array $data): \App\Models\ClientDesignation
    {
        if (! empty($data['designation_id'])) {
            $row = \App\Models\ClientDesignation::forClient($client->id)->whereKey((int) $data['designation_id'])->first();
        } else {
            $row = \App\Models\ClientDesignation::whereNull('client_id')->where('name', (string) ($data['designation'] ?? ''))->first();
        }

        return $row ?? throw ValidationException::withMessages(['designation' => 'Choose a designation.']);
    }

    /**
     * Change a login's designation (and so its access). An inventor login stays an inventor; a
     * login cannot change its own designation (another admin or the firm does).
     */
    public function changeDesignation(Client $client, User $user, \App\Models\ClientDesignation $designation, User $actor, ?string $ip = null): array
    {
        if (! $this->belongsTo($client, $user)) {
            throw ValidationException::withMessages(['user' => 'This user is not linked to this client.']);
        }
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['user' => 'You cannot change your own access. Ask another admin or the firm.']);
        }
        if ((bool) $designation->is_inventor !== ($user->role === 'inventor')) {
            throw ValidationException::withMessages(['designation' => 'An inventor login keeps the Inventor designation; other logins cannot become inventors here.']);
        }
        $before = ['role' => $user->role, 'designation_id' => $user->client_designation_id];
        DB::transaction(function () use ($client, $user, $designation, $actor, $ip, $before) {
            $user->role = \App\Support\ClientAccess::roleFor((array) $designation->permissions, (bool) $designation->is_inventor);
            $user->client_designation_id = $designation->id;
            $user->save();
            ClientContact::where('client_id', $client->id)->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
                ->update(['designation' => $designation->name, 'title' => $designation->name]);
            AuditLog::create(['user_id' => $actor->id, 'action' => 'portal_user_access_changed', 'subject_type' => 'Client', 'subject_id' => $client->id,
                'metadata' => ['user_id' => $user->id, 'designation' => $designation->name, 'before' => $before, 'role' => $user->role, 'client_id' => $client->id],
                'ip_address' => $ip]);
        });

        return $this->row($user->fresh(), $designation->name, null, (int) $client->portal_user_id === $user->id);
    }

    private function row(User $user, ?string $designation, ?string $phone, bool $primary): array
    {
        $designationRow = $user->client_designation_id ? \App\Models\ClientDesignation::find($user->client_designation_id) : null;

        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'phone'       => $phone,
            'role'        => $user->role,
            'designation' => $designationRow?->name ?? $designation,
            'designation_id' => $designationRow?->id,
            'permissions' => \App\Support\ClientAccess::permissions($user),
            'access'      => self::ACCESS_LABEL[$user->role] ?? $user->role,
            'is_primary'  => $primary,
            'status'      => $user->status,
            'created_at'  => $user->created_at?->toDateTimeString(),
        ];
    }
}
