<?php

namespace App\Console\Commands;

use App\Mail\CpiBookingMail;
use App\Models\CpiBooking;
use App\Services\CpiBookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendSampleBookingEmails extends Command
{
    protected $signature = 'cpi:sample-booking-emails {email : where to send the samples} {--visitor-zone=America/New_York : the made-up visitor\'s time zone}';
    protected $description = 'Send sample consultation emails (visitor, team, staff, reschedule, cancel) with made-up booking details';

    public function handle(CpiBookingService $svc): int
    {
        $tz = CpiBookingService::settings()['timezone'];
        $start = now($tz)->addDays(3)->setTime(10, 0);
        $b = new CpiBooking([
            'name' => 'Asha Rao', 'email' => (string) $this->argument('email'), 'organization' => 'Acme Robotics Pvt Ltd', 'visitor_timezone' => (string) $this->option('visitor-zone'),
            'topic' => "We have built a vision model that inspects weld seams on a moving line.\nWe are about to demo it at a trade fair next month and want to know whether to file something first.",
            'start_utc' => $start->copy()->utc(), 'end_utc' => $start->copy()->addMinutes(30)->utc(), 'status' => 'confirmed', 'sequence' => 1,
        ]);
        $b->id = 0;
        $b->setRelation('staff', collect());
        $c = [
            'tzCompany' => $tz, 'tzVisitor' => $b->visitorZone($tz), 'manageUrl' => 'https://canpatentit.com/booking/manage#0.sample', 'cancelUrl' => 'https://canpatentit.com/booking/manage?action=cancel#0.sample',
            'joinUrl' => CpiBookingService::settings()['meeting_url'] ?: 'https://meet.google.com/sample-room-link', 'staff' => ['Priya Nair', 'Karthik Menon'], 'bookUrl' => 'https://canpatentit.com/book',
        ];
        $ics = $svc->ics($b);
        $to = (string) $this->argument('email');
        $old = $b->start_utc->copy()->subDay();

        Mail::to($to)->send(CpiBookingMail::confirmation($b, $c, $ics));
        Mail::to($to)->send(CpiBookingMail::notification($b, $c, $ics));
        Mail::to($to)->send(CpiBookingMail::staffInvite($b, $c, $ics));
        Mail::to($to)->send(CpiBookingMail::rescheduled($b, $c, 'visitor', $old, $ics, 'visitor'));
        Mail::to($to)->send(CpiBookingMail::cancelled($b, $c, 'visitor', $svc->ics($b, 'CANCEL')));
        $this->info("Five sample emails sent to {$to} (visitor zone {$c['tzVisitor']}).");

        return self::SUCCESS;
    }
}
