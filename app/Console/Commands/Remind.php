<?php namespace App\Console\Commands;

use App\Models\DueList;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Mail;
use DB;

class Remind extends Command {

    /**
    * The console command name.
    *
    * @var string
    */
    protected $name = 'remind';

    /**
    * The console command description.
    *
    * @var string
    */
    protected $description = 'Send out emails and SMSes with a reminder';

    /**
    * Execute the console command.
    *
    * @return mixed
    */
    public function handle(){

    $call_details = $this->send_reminders(date("Y-m-d"));
    }

    public function send_email(){

    }
    public function send_sms(){

    }

    public function send_reminders($date_today){
    include(storage_path().'/sms.php');
    $due_list_obj = new DueList;
    $call_details = $due_list_obj->get_reminder_list();

    $all_numbers = array();
    $all_names = array();   

    // Type 1 email and sms
    $cc_id_arr = [];
    foreach($call_details['beginweek'] as $val){
      if(array_key_exists($val->cc_id, $cc_id_arr))
        $cc_id_arr[$val->cc_id] []= $val;
      else
        $cc_id_arr[$val->cc_id] = array($val);
    }   
    $this->generate_notifications($cc_id_arr,2,'begin_week_mail', $all_numbers, $all_names);


    // Type 2 email and sms
    $cc_id_arr = [];
    foreach($call_details['midweek'] as $val){
      if(array_key_exists($val->cc_id, $cc_id_arr))
        $cc_id_arr[$val->cc_id] []= $val;
      else
        $cc_id_arr[$val->cc_id] = array($val);
    }   
    $this->generate_notifications($cc_id_arr,4,'general_mail', $all_numbers, $all_names);

    $cc_id_arr = [];
    foreach($call_details['endweek'] as $val){
      if(array_key_exists($val->cc_id, $cc_id_arr))
        $cc_id_arr[$val->cc_id] []= $val;
      else
        $cc_id_arr[$val->cc_id] = array($val);
    }   
    $this->generate_notifications($cc_id_arr,4,'general_mail', $all_numbers, $all_names);


    $cc_id_arr = [];
    foreach($call_details['postweek'] as $val){
      if(array_key_exists($val->cc_id, $cc_id_arr))
        $cc_id_arr[$val->cc_id] []= $val;
      else
        $cc_id_arr[$val->cc_id] = array($val);
    }   
    $this->generate_notifications($cc_id_arr,4,'general_mail', $all_numbers, $all_names);   

    $email = "shashank@sevasetu.org";    
    $bcc = explode(',',$_ENV['BCC_IDS']);
    $sent=Mail::send("emails.reminder_multiple",
              array('cc_name'=>"cron",
                  'mother_name'=>"test",
                  'count'=>serialize($all_numbers)."*****".serialize($all_names)
                 ), 
              function($message) use($email,$bcc){
                $message
                ->to($email)
                ->subject('Seva Setu: Call reminder')
                ->bcc($bcc);
                }
              );

    }


    public function generate_notifications($cc_id_arr,$sms_id,$mail_type, &$all_numbers, &$all_names)
    {
    $bcc = explode(',',$_ENV['BCC_IDS']);
    foreach($cc_id_arr as $callchamp){
      $all_numbers []= $callchamp[0]->cc_phonenumber;
      $all_names []= $callchamp[0]->cc_name;
      
      //Send SMS to call champion
      if(count($callchamp) > 1){
        send_sms($sms_id, array($callchamp[0]->cc_name, $callchamp[0]->cc_phonenumber, count($callchamp)));
        
        //Send an email
        if($mail_type == 'beginweek')
          $mail = 'emails.reminder_multiple';
        else
          $mail = 'emails.reminder_multiple_general';
        $email = $callchamp[0]->cc_email;
        $sent=Mail::send($mail,
                array('cc_name'=>$callchamp[0]->cc_name,
                    'mother_name'=>$callchamp[0]->mother_name,
                    'count'=>count($callchamp)
                   ), 
                function($message) use($email,$bcc){
                  $message
                  ->to($email)
                  ->subject('Seva Setu: Call reminder')
                  ->bcc($bcc);
                  }
                );

        $mentees=DB::table('mct_call_champions')
                        ->join('mct_callchampion_shadow', 'mentee', '=', 'cc_id')
                        ->join('mct_user', 'user_id', '=', 'fk_user_id')
                        ->where('mct_call_champions.activation_status',1)
                        ->where('mct_callchampion_shadow.mentor' ,'=', $callchamp[0]->cc_id)
                        ->get();


                foreach ($mentees as $value)
          {
            send_sms($sms_id, array($value->v_name, $value->i_phone_number, count($callchamp)));
            
            $email = $value->v_email;
          $sent=Mail::send($mail,
                array('cc_name'=>$value->v_name,
                    'mother_name'=>$callchamp[0]->mother_name,
                    'count'=>count($callchamp)
                   ), 
                function($message) use($email,$bcc){
                  $message
                  ->to($email)
                  ->subject('Seva Setu: Call reminder')
                  ->bcc($bcc);
                  }
                );  
          }
      }
      
      else{
        send_sms($sms_id+1, array($callchamp[0]->cc_name,      $callchamp[0]->cc_phonenumber, 
              $callchamp[0]->mother_name, 
              $callchamp[0]->mother_phonenumber
              )
            );
        
        //Send an email
        if($mail_type == 'beginweek')
          $mail = 'emails.reminder_single';
        else
          $mail = 'emails.reminder_single_general';
        $email = $callchamp[0]->cc_email;
        $sent=Mail::send($mail,
                array('cc_name'=>$callchamp[0]->cc_name,
                    'mother_name'=>$callchamp[0]->mother_name,
                    'number'=>$callchamp[0]->mother_phonenumber,
                    'village'=>$callchamp[0]->mother_village
                  ), 
                function($message) use($email,$bcc){
                  $message
                  ->to($email)
                  ->subject('Seva Setu: Call reminder')
                  ->bcc($bcc);
                  }
                );


        $mentees=DB::table('mct_call_champions')
                        ->join('mct_callchampion_shadow', 'mentee', '=', 'cc_id')
                        ->join('mct_user', 'user_id', '=', 'fk_user_id')
                        ->where('mct_call_champions.activation_status',1)
                        ->where('mct_callchampion_shadow.mentor' ,'=', $callchamp[0]->cc_id)
                        ->get();


                foreach ($mentees as $value)
          {
            send_sms($sms_id + 1, array($value->v_name, $value->i_phone_number, $callchamp[0]->mother_name, 
              $callchamp[0]->mother_phonenumber));
            
            $email = $value->v_email;
          $sent=Mail::send($mail,
                array('cc_name'=>$value->v_name,
                    'mother_name'=>$callchamp[0]->mother_name,
                    'number'=>$callchamp[0]->mother_phonenumber,
                    'village'=>$callchamp[0]->mother_village
                   ), 
                function($message) use($email,$bcc){
                  $message
                  ->to($email)
                  ->subject('Seva Setu: Call reminder')
                  ->bcc($bcc);
                  }
                );  
          }
      }
      
      //Send SMS to mother
      //foreach($callchamp as $details)
        //send_sms(6, array($details->mother_phonenumber));
    }

    }

  

}
