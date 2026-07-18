<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $workflows = [
            'PRV' => ['US provisional filing', ['Instructions confirmed', 'Disclosure reviewed', 'Provisional drafted', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded']],
            'NPV' => ['US utility nonprovisional filing', ['Instructions confirmed', 'Specification and claims drafted', 'IDS and formalities prepared', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded']],
            'NPD' => ['US design application', ['Instructions confirmed', 'Drawings prepared', 'Formalities reviewed', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded']],
            'NPP' => ['US plant application', ['Instructions confirmed', 'Specification prepared', 'Botanical material reviewed', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded']],
            'NPS' => ['US PCT national stage', ['National-stage instruction', '371 documents and fees prepared', 'Translation and IDS reviewed', 'Attorney review', 'Client approval', 'National-stage filing', 'Filing receipt recorded']],
            'CNS' => ['US continuation application', ['Copendency verified', 'Continuation claims prepared', 'Benefit claim reviewed', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded']],
            'DIV' => ['US divisional application', ['Restriction requirement reviewed', 'Divisional claims prepared', 'Benefit claim reviewed', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded']],
            'CIP' => ['US continuation-in-part', ['New matter approved', 'Specification and claims prepared', 'Benefit claim reviewed', 'Attorney review', 'Client approval', 'USPTO filing', 'Filing receipt recorded']],
            'OAR' => ['US Office action response', ['Office action docketed', 'Rejections analysed', 'Amendment and arguments drafted', 'Attorney review', 'Client approval', 'Response filed', 'Awaiting USPTO action']],
            'AFT' => ['US after-final strategy', ['Final action docketed', 'Interview and strategy assessed', 'Amendment or petition drafted', 'Attorney review', 'Client approval', 'Submission filed', 'Next route confirmed']],
            'RCE' => ['US request for continued examination', ['Final disposition reviewed', 'Claims and amendment prepared', 'RCE papers and fees prepared', 'Attorney review', 'Client approval', 'RCE filed', 'Awaiting examination']],
            'APP' => ['US PTAB ex parte appeal', ['Appealable rejection docketed', 'Notice of appeal filed', 'Appeal brief prepared', 'Examiner answer reviewed', 'Reply/oral hearing prepared', 'PTAB decision docketed', 'Next action confirmed']],
            'ISF' => ['US issue fee', ['Notice of allowance docketed', 'Issue fee and entity status verified', 'Client authority confirmed', 'Issue fee paid', 'Issue date monitored', 'Patent issued', 'Service engagement closed']],
            'M35' => ['US 3.5 year maintenance', ['Window opened', 'Entity status and fee verified', 'Client authority confirmed', 'Maintenance fee paid', 'Receipt recorded', 'Grace period monitored', 'Service engagement closed']],
            'M75' => ['US 7.5 year maintenance', ['Window opened', 'Entity status and fee verified', 'Client authority confirmed', 'Maintenance fee paid', 'Receipt recorded', 'Grace period monitored', 'Service engagement closed']],
            'M15' => ['US 11.5 year maintenance', ['Window opened', 'Entity status and fee verified', 'Client authority confirmed', 'Maintenance fee paid', 'Receipt recorded', 'Grace period monitored', 'Service engagement closed']],
            'REI' => ['US reissue', ['Reissue issue identified', 'Error and oath prepared', 'Claims prepared', 'Attorney review', 'Client approval', 'USPTO filing', 'Awaiting examination']],
            'XPR' => ['US ex parte reexamination', ['Request or order docketed', 'Claims and references analysed', 'Response prepared', 'Attorney review', 'Client approval', 'Submission filed', 'Awaiting USPTO action']],
            'REV' => ['US revival petition', ['Abandonment docketed', 'Unintentional delay reviewed', 'Petition and fee prepared', 'Attorney review', 'Client approval', 'Petition filed', 'Decision monitored']],
            'IPR' => ['US PTAB inter partes review', ['Petition or institution docketed', 'Party role and deadlines confirmed', 'Evidence and expert work prepared', 'Briefing filed', 'Oral hearing prepared', 'Final written decision docketed', 'Post-decision action confirmed']],
            'PGR' => ['US PTAB post-grant review', ['Petition or institution docketed', 'Party role and deadlines confirmed', 'Evidence and expert work prepared', 'Briefing filed', 'Oral hearing prepared', 'Final written decision docketed', 'Post-decision action confirmed']],
        ];
        foreach ($workflows as $code => [$name, $stages]) {
            $id = DB::table('jurisdiction_lifecycle_templates')->insertGetId(['jurisdiction'=>'US','service_code'=>$code,'name'=>$name,'version'=>'2026.3','effective_from'=>'2026-01-01','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            foreach ($stages as $i => $stage) DB::table('jurisdiction_lifecycle_stages')->insert(['jurisdiction_lifecycle_template_id'=>$id,'stage_code'=>'S'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),'stage_name'=>$stage,'sequence_order'=>$i,'target_duration_days'=>0,'gate_criteria'=>json_encode([]),'created_at'=>now(),'updated_at'=>now()]);
        }
        foreach ([['PRV','NPV',null,null],['NPV','OAR','us_nonfinal_office_action',null],['NPS','OAR','us_nonfinal_office_action',null],['CNS','OAR','us_nonfinal_office_action',null],['DIV','OAR','us_nonfinal_office_action',null],['CIP','OAR','us_nonfinal_office_action',null],['OAR','AFT','us_final_office_action',null],['OAR','ISF','us_notice_of_allowance',null],['AFT','RCE',null,null],['AFT','APP',null,null],['RCE','OAR','us_nonfinal_office_action',null],['APP','RCE','us_ptab_decision',null],['APP','ISF','us_notice_of_allowance',null],['OAR','CNS',null,null],['OAR','DIV',null,null],['OAR','CIP',null,null],['ISF','M35','us_patent_issued','Issued'],['M35','M75',null,'Issued'],['M75','M15',null,'Issued']] as [$from,$to,$event,$status]) DB::table('service_transition_rules')->insert(['jurisdiction'=>'US','from_service_code'=>$from,'to_service_code'=>$to,'required_event_type'=>$event,'required_application_status'=>$status,'description'=>"US successor $from to $to",'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void { $ids=DB::table('jurisdiction_lifecycle_templates')->where('jurisdiction','US')->where('version','2026.3')->pluck('id'); DB::table('jurisdiction_lifecycle_stages')->whereIn('jurisdiction_lifecycle_template_id',$ids)->delete(); DB::table('jurisdiction_lifecycle_templates')->whereIn('id',$ids)->delete(); DB::table('service_transition_rules')->where('jurisdiction','US')->delete(); }
};
