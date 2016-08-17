<?php namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Models\Fieldworkers;
use App\Models\CallChampion;
use App\Models\Users;
use App\Models\DueList;
use App\Models\Checklist;


use Carbon\Carbon;
use Request;
use Mail;
use Auth;
use DB; 
use Hash;
use Validator;
use Excel;
use Session;
use Illuminate\Support\Facades\Input;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Auth\UserInterface;
use Illuminate\Http\Response;
use Hashids\Hashids;
use \Maatwebsite\Excel\Files\ImportHandler;
use App\Http\Helpers; 
use App\Models\App\Models;


class BeneficiaryController extends Controller{
	protected $model;
	public $title="Admin Login";
	
	public $user_id;
	public $role_id;
	public $role_type;
	
	protected $helper;
	protected $role_permissions;
	
	public function __construct(){
		if(!Session::has('user_logged')){
			Redirect::to('/')->send();
		}
		else{
			$userinfo=Session::get('user_logged');
			$this->user_id=$userinfo['user_id'];
			$this->role_id=$userinfo['role_id'];
			$this->role_type=$userinfo['v_role'];
		}
	}

	// make the input to this function an excel
	public function add_beneficiary_list(Request $request, $field_worker_id){
		$data = import_excel($input);
		foreach($data as $beneficiary_data){
			// add a beneficiary into the system
			$beneficiary_id = $this->add_new_beneficiary($beneficiary_data, $field_worker_id);
			
			// prepare the due list for registered beneficiary
			$due_list_ids = $this->add_due_list($beneficiary_id, $delivery_date);
			
			// assign a call champion	
			// This assigns a call champion to all the due-list IDs belonging to a beneficiary
			// Another way is call champions are assigned to due-list IDs individually 
			$result3 = $this->allocate_call_champion($due_list_ids, $beneficiary_id);
			if(!$result1 or !$result2 or !result3)
				die("exception");
		}
		return true;
	}
	public function add_new_beneficiary($beneficiary_data, $field_worker_id){
		// Model beneficiary::add_beneficiary
		$beneficiary_obj = new Beneficiary;
		$beneficiary_id = $beneficiary_obj->insert($beneficiary_data);
		if(!$beneficiary_id)
			die("exception");
		
		return $beneficiary_id;
	}
	
	public function tester_method(){
		 die("test done");
	}

	public function batch_assignment_callchampion($beneficiary_ids, $cc_id = -1)
	{
		foreach($beneficiary_ids as $bene){
			$due_list = $this->add_due_list($bene->b_id, $bene->dt_due_date);
			$this->allocate_call_champion($due_list, $bene->b_id, $cc_id);
			$this->update_call_champion_report($due_list);			
		}
		return Redirect::back();
	}

	public function upload_mother(){
		$bene_obj = Beneficiary::all();

		foreach($bene_obj as $bene){
			// add a check - if mother doesnt exist in the DB, only then do the following -
			$due_list = $this->add_due_list($bene->b_id, $bene->dt_due_date);
			$this->allocate_call_champion($due_list, $bene->b_id, $cc_id);
			$this->update_call_champion_report($due_list);			
		}
		
		 die("mother upload done");
	}
	
	public function add_due_list($beneficiary_id, $delivery_date){
		$due_list = $this->calculate_due_list($delivery_date);
		$duelist_obj = new DueList;
		return $duelist_obj->update_due_list($beneficiary_id, $due_list);
	}
	
	public function calculate_due_list($delivery_date){
		// LMP: Last Menstrual Period
		$LMP_DATE_CALC_WEEKS = 39;
		$begin_date  = date("Y-m-d", strtotime('-'.$LMP_DATE_CALC_WEEKS.' weeks',strtotime($delivery_date)));
		$weeks_to_add = array(0, 12, 16, 26, 36, 40, 46, 50, 54, 64);
		$due_list = array();
		for($i=0;$i<count($weeks_to_add);$i++){
			$due_list []= date("Y-m-d", strtotime('+'.$weeks_to_add[$i].' weeks',strtotime($begin_date)));
		}
		
		return $due_list;
	}
	
	public function allocate_call_champion($due_list_ids_arr = array(), $beneficiary_id = -1, $cc_id = -1, $MAX_ALLOWED_PER_CC = 60 ){
		if($beneficiary_id == -1)
			$beneficiary_id = $this->get_beneficiary_id($due_list_ids_arr);
		
		// the input to the method below may have to be due_list_ids_arr
		$prev_champ_ids = $this->get_previous_call_champions_for_beneficiary($beneficiary_id);
		if(!$prev_champ_ids){
			if($cc_id == -1)
				$call_champ_id = $this->randomly_select_call_champ();
			else
				$call_champ_id = $cc_id;
			return $this->assign_call_champion_duelist_id($call_champ_id, $due_list_ids_arr);
		}
		else{
			$existing_assign_counts = $this->check_existing_assignments($prev_champ_ids);
			for($i=0; $i<count($prev_champ_ids); $i++){
				$call_champ_id = $prev_champ_ids[$i]->cc_id;
				if($existing_assign_counts[$call_champ_id] < $MAX_ALLOWED_PER_CC){
					return $this->assign_call_champion_duelist_id($call_champ_id, $due_list_ids_arr);
				}
			}
			// This means all past users are aboove their threshold
			// In which case randomly assign one
			$call_champ_id = $this->randomly_select_call_champ();
			return $this->assign_call_champion_duelist_id($call_champ_id, $due_list_ids_arr);
		}
	}
	
	public function randomly_select_call_champ(){
		$call_champ_obj = new CallChampion;
		$list_champ = $call_champ_obj->get_active_call_champions();
		$rand_ind = rand(0,count($list_champ)-1);
		return $list_champ[$rand_ind];
	}
	
	public function check_existing_assignments($prev_champ_ids){
		$list = array();
		for($i = 0; $i<count($prev_champ_ids); $i++)
			$list []= $prev_champ_ids[$i]->cc_id;
		

		$due_list_obj = new DueList;
		$existing_counts = $due_list_obj->get_existing_assignments($list);
		
		//prepare output as a key-value pair
		return $existing_counts;
	}
	
	public function get_previous_call_champions_for_beneficiary($beneficiary_id){
		// the input to the method below may have to be due_list_ids_arr
		$due_list_obj = new DueList;
		$list = $due_list_obj->get_previous_call_champions($beneficiary_id);
		return $list;
	}
	
	public function assign_call_champion_beneficiary_id($beneficiary_id, $call_champ_id){
		$due_list_obj = new DueList;
		$due_list_ids_arr = $due_list_obj->get_beneficiary_duelist_id($beneficiary_id);
		return $due_list_obj->assign_call_champion_duelist_id($call_champ_id, $due_list_ids_arr);
	}
	
	public function assign_call_champion_duelist_id($call_champ_id, $due_list_ids_arr){
		$due_list_obj = new DueList;
		return $due_list_obj->assign_call_champion_duelist_id($call_champ_id, $due_list_ids_arr);
	}
	
	public function update_call_champion_report($due_list){
		$table_name = 'mct_callchampion_report';
		$insert_array = [];
		foreach($due_list as $val)
			$insert_arr []= array('fk_due_id'=>$val);
		
		DB::table($table_name)
			->insert($insert_arr);
		
		// Needs exception handling here
		return true;
	}
	
	public function list_all_beneficiaries(){
		
	}

	// download link for sample excel
	public function downloadExcel()
	{
		return response()->download(public_path('download\sample.xlsx'));
	}

/** 
* Save the validated data to database.
* @input $excel_data saved in method @importExcel
* @output Saves data in database. 
* called by route /data/final-upload
* @algorithm
* 1. Run foreach on $exceldata.
* 2. Validate the data and store the valid data to database.
* 3. Return a message to Route::get /data/upload that messages are stored.
*/

	public function Excel_data_upload()
	{
		Session::flash('should_data_be_inserted',1);
		$data=Session::get('excel_data');
		$data->each(function($r){
			
					$beneficiary= new Beneficiary;
		  			$rules = array(
		  			 'v_name' => 'required|min:3|max:20|Regex:/^[ A-Za-z]+[A-Za-z0-9.\' ]*$/',
  					'v_phone_number'=>'required|numeric|digits_between:10,10',
  					'dt_due_date'=>'required'
		  			);
 				$dt_due_date=str_replace("-", "/", $r->date_of_delivery);
    			$beneficiary_data = array(
    					'fk_f_id'=>$r->field_worker_id,
    					'v_name' => $r->womans_name,
    					'v_husband_name'=>$r->fatherspouse_name,
    					'i_age'=>$r->age,
    					'v_phone_number'=>$r->mobile_no,
    					'v_awc_number'=>$r->awc_code,
    					'v_village_name'=>$r->village_name,
    					'dt_due_date'=>''
    			);
    			if($r->date_of_delivery!='')
    			{
    				$beneficiary_data['dt_due_date']=date('d/m/y',strtotime($dt_due_date));
    				$var=Carbon::createFromFormat('d/m/y', $beneficiary_data['dt_due_date']);
    				if($var!='')
    				$beneficiary_data['dt_due_date']=$var;
    				else
					$beneficiary_data['dt_due_date']=null;
    			}
    			else
    			{
    				$beneficiary_data['dt_due_date']=null;	
    			}
    			$already_exist_number=Beneficiary::where('v_phone_number',$beneficiary_data['v_phone_number'])->first();
    			if($already_exist_number['v_phone_number']!='')
    			{
     				Session::flash('should_data_be_inserted',0);
    			}
    			$validator = Validator::make($beneficiary_data, $rules);
    			if ($validator->fails())
    			{
     			}
    			else if(Session::get('should_data_be_inserted')==1)
    			{
    				$beneficiary->fk_f_id=$beneficiary_data['fk_f_id'];
    				$beneficiary->v_name= $beneficiary_data['v_name'];
     				$beneficiary->v_husband_name=$beneficiary_data['v_husband_name'];
    				$beneficiary->i_age=$beneficiary_data['i_age'];
    				$beneficiary->v_phone_number=$beneficiary_data['v_phone_number'];
    				$beneficiary->v_awc_number=$beneficiary_data['v_awc_number'];
    				$beneficiary->v_village_name=$beneficiary_data['v_village_name'];
    				$beneficiary->dt_due_date=$var;
    				$beneficiary->save();
    			}
    			Session::forget('should_data_be_inserted');
     			Session::flash('should_data_be_inserted',1);	
 		});
 		Session::forget('excel_data');
		Session::flash('message', 'Mothers data with complete information uploaded');
		return back();
	}


/** 
* Takes data of all mother from excel file and validates data and stores data in session.
* @input Data from excel file
* it validates data of excel file(data_of_delivery & mothername & phone no.) and saves data in Session (variable name $excel_data ) 
* @calling_method Invoked as we go on post route /data/upload
* @algorithm
* 1. Load Excel file and data will be stored in $reader (array).
* 2. Apply foreach on this array and get data of each row of excel file.
* 3. On each data apply validation rules and accordingly store the message for 		particular type of error
*/	

	public function importExcel()
	{
		//this variable tells whether data is error free and can be stored or not.
		Session::flash('should_data_be_inserted',1);
		//variable for different types of errors and warnings.
		Session::flash('count_excelupload_errors.count',0);
		Session::flash('count_excelupload_warning.count',0);
		Session::flash('count_excelupload_data_repeated.count',0);	
		//loads excel file
		Excel::load(Input::file('beneficiaries_data'),function($reader)
 		{

 			//$reader acts differently in different excel formats
			if(Input::file('beneficiaries_data')->getClientOriginalExtension()=='csv')
				$data=$reader->get();
			else
			{
				$data=$reader->get();
				$data=$data[0];
			}
			  $data->each(function($r){
					$beneficiary= new Beneficiary;
					//validation rules
		  			$rules = array(
		  			 'v_name' => 'required|min:3|max:20|Regex:/^[ A-Za-z]+[A-Za-z0-9.\' ]*$/',
  					'v_phone_number'=>'required|numeric|digits_between:10,10',
  					'dt_due_date'=>'required'
		  			);
 				$dt_due_date=str_replace("-", "/", $r->date_of_delivery);
    			$beneficiary_data = array(
    					'fk_f_id'=>$r->field_worker_id,
    					'v_name' => $r->womans_name,
    					'v_husband_name'=>$r->fatherspouse_name,
    					'i_age'=>$r->age,
    					'v_phone_number'=>$r->mobile_no,
    					'v_awc_number'=>$r->awc_code,
    					'v_village_name'=>$r->village_name,
    					'dt_due_date'=>''//date('d/m/y',strtotime($dt_due_date))
    			);
    			//validating dates as by default date value is 1970/1/1 thus chaning that to null so that validation rules work correctly
    			if($r->date_of_delivery!='')
    			{
    				$beneficiary_data['dt_due_date']=date('d/m/y',strtotime($dt_due_date));
    				$var=Carbon::createFromFormat('d/m/y', $beneficiary_data['dt_due_date']);
    				if($var!='')
    				$beneficiary_data['dt_due_date']=$var;
    				else
					$beneficiary_data['dt_due_date']=null;
    			}
    			else
    			{
    				$beneficiary_data['dt_due_date']=null;	
    			}
    			// checks for multiple combinations of mother name and hsuband name in database.
 				$already_exist_name=Beneficiary::where(['v_name'=>$beneficiary_data['v_name'],'v_husband_name'=>$beneficiary_data['v_husband_name']])->first();
 				if($already_exist_name['v_name']!='')
 				{
    				$count_excelupload_warning=Session::get('count_excelupload_warning.count');
 					Session::flash('count_excelupload_warning'.$count_excelupload_warning,$r->srno);
 					Session::flash('count_excelupload_warning.message'.$count_excelupload_warning,'Combination of Mother name and Husband name already exist');					
     				$count_excelupload_warning++;
     				Session::forget('count_excelupload_warning.count');
     				Session::flash('count_excelupload_warning.count',$count_excelupload_warning);
 				}
 				// check for the data if it already exists in database.
    			$already_exist_number=Beneficiary::where('v_phone_number',$beneficiary_data['v_phone_number'])->first();
    			if($already_exist_number['v_phone_number']!='')
    			{
    				$count_excelupload_data_repeated=Session::get('count_excelupload_data_repeated.count');
 					Session::flash('count_excelupload_data_repeated'.$count_excelupload_data_repeated,$r->srno);
 					Session::flash('count_excelupload_data_repeated.message'.$count_excelupload_data_repeated,'Identical Data exists in database');
     				$count_excelupload_data_repeated++;
     				Session::forget('count_excelupload_data_repeated.count');
     				Session::flash('count_excelupload_data_repeated.count',$count_excelupload_data_repeated);
     				Session::forget('should_data_be_inserted');
     				Session::flash('should_data_be_inserted',0);
    			}
    			$validator = Validator::make($beneficiary_data, $rules);
    			if ($validator->fails())
    			{
    				//find the reason for which our validation fails and store that reason in message.
    				$failedRules = $validator->failed();
    				$error_message='';
					if(isset($failedRules['v_phone_number'])) 
					{
						$error_message=$error_message."    "."Phone Number Missing/Incorrect";
					}
					if(isset($failedRules['v_name']))
					{
						if(isset($failedRules['v_phone_number']))
						{
							$error_message=$error_message.",";	
						}
						$error_message=$error_message."    "."Mother Name Missing";
					}
					 if(isset($failedRules['dt_due_date']))
					 { 
					 	if(isset($failedRules['v_name'])||isset($failedRules['v_phone_number']))
					 	{
					 		$error_message=$error_message.",";
					 	}
					 	$error_message=$error_message."    "."Date Of Delivery Missing";
					 }
    				$count_excelupload_errors=Session::get('count_excelupload_errors.count');
 					Session::flash('count_excelupload_errors'.$count_excelupload_errors,$r->srno);
					Session::flash('count_excelupload_errors.message'.$count_excelupload_errors,$error_message);
     				$count_excelupload_errors++;
     				Session::forget('count_excelupload_errors.count');
     				Session::flash('count_excelupload_errors.count',$count_excelupload_errors);
     			}
    			else if(Session::get('should_data_be_inserted')==1)
    			{
    				// $beneficiary->fk_f_id=$beneficiary_data['fk_f_id'];
    				// $beneficiary->v_name= $beneficiary_data['v_name'];
     			// 	$beneficiary->v_husband_name=$beneficiary_data['v_husband_name'];
    				// $beneficiary->i_age=$beneficiary_data['i_age'];
    				// $beneficiary->v_phone_number=$beneficiary_data['v_phone_number'];
    				// $beneficiary->v_awc_number=$beneficiary_data['v_awc_number'];
    				// $beneficiary->v_village_name=$beneficiary_data['v_village_name'];
    				// $beneficiary->dt_due_date=$var;
    				// $beneficiary->save();
    			}
    			Session::forget('should_data_be_inserted');
     			Session::flash('should_data_be_inserted',1);	
 		});
				//save the above excel data in variable an then finally store this data when user makes confirmation by calling method @Excel_data_upload
				Session::put('excel_data',$data);
 	});
		Session::flash('data_validated',1);
	return back();
	}		
}
