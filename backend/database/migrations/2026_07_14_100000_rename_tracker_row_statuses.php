<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename legacy tracker_rows.status values to the new canonical set.
     * Old values that already match the new names are left untouched.
     */
    private const MAP = [
        'To schedule call'                         => 'Discovery Call Scheduled',
        'Scheduled call with client'               => 'Discovery Call Done',
        'Conducting search'                        => 'Prior Art Search',
        'shared search report'                     => 'Search Report Shared',
        'Shared search report'                     => 'Search Report Shared',
        'Shared key features'                      => 'Search Report Shared',
        'Awaiting the draft from the client'       => 'Awaiting IDF from Client',
        'Patent Drafting'                          => 'Drafting in Progress',
        'shared draft & drawings'                  => 'Draft Shared with Client',
        'To share the claims with client'          => 'Claims Ready to Share',
        'Client Review'                            => 'Draft Shared with Client',
        'Awaiting feedback'                        => 'Awaiting Client Feedback',
        'received Comments from client - to Update' => 'Client Comments Received',
        'Awaiting signed forms'                    => 'Awaiting Signed Forms',
        'To file'                                  => 'Ready to File',
        'Awaiting payment'                         => 'Awaiting Payment',
        'Provisional Filing Prep'                  => 'Provisional or Complete Filing Prep',
        'Provisional Filed'                        => 'Complete or Provisional Filed',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('tracker_rows')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        // Reverse only unambiguous renames (multi-source merges cannot be reversed)
        $reversible = [
            'Discovery Call Scheduled' => 'To schedule call',
            'Discovery Call Done'      => 'Scheduled call with client',
            'Prior Art Search'         => 'Conducting search',
            'Awaiting IDF from Client' => 'Awaiting the draft from the client',
            'Drafting in Progress'     => 'Patent Drafting',
            'Claims Ready to Share'    => 'To share the claims with client',
            'Awaiting Signed Forms'    => 'Awaiting signed forms',
            'Ready to File'                        => 'To file',
            'Awaiting Payment'                     => 'Awaiting payment',
            'Provisional or Complete Filing Prep'  => 'Provisional Filing Prep',
            'Complete or Provisional Filed'        => 'Provisional Filed',
            'Awaiting Client Feedback' => 'Awaiting feedback',
            'Client Comments Received' => 'received Comments from client - to Update',
        ];
        foreach ($reversible as $new => $old) {
            DB::table('tracker_rows')->where('status', $new)->update(['status' => $old]);
        }
    }
};
