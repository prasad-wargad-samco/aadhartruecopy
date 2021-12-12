<?php

namespace Samco\Aadhartruecopy;
use Samco\Aadharkhosla\Models\ESignVendorsMaster;

class Aadhartruecopy{
	/* Function to upload a file to TRUECOPY and get the response either SUCCESS/FAILS.
	 * If file successfully uploaded then redirecting it to the TRUECOPY website for user to enter AADHAR ID
	 */
	private $APP_ENV, $TRUECOPY_DOMAIN, $TRUECOPY_APIKEY;
	private $flag_vendor_details_found = true;
	public function __construct(){
		$esign_vendor_obj = new ESignVendorsMaster;
		$vendor_details = $esign_vendor_obj->getESignVendors(array('name' => 'TRUECOPY', 'status' => 1));
		if($vendor_details->isEmpty()){
			$this->flag_vendor_details_found = false;
		}
		else{
			$vendor_details = $vendor_details[0];	// retrieving 1st record from available data
			// retrieving application environment, whether it's set to PRODUCTION or DEVELOPMENT.
			$this->APP_ENV = env('APP_ENV');
			if(strtolower($this->APP_ENV) == 'production'){
				// setting up PRODUCTION credentials
				$this->TRUECOPY_DOMAIN = $vendor_details->live_domain_url;
				$this->TRUECOPY_APIKEY = $vendor_details->live_api_key;
			}
			else{
				// setting up DEVELOPMENT credentials
				$this->TRUECOPY_DOMAIN = $vendor_details->uat_domain_url;
				$this->TRUECOPY_APIKEY = $vendor_details->uat_api_key;
			}
		}
	}

	/* Function to upload a document for signature and initiate signature process if upload is successful
	 */
	public function upload($input_arr = array()){
		/* Possible values for $input_arr are: array('uuid' => <Unique ID/Reference ID used for identifying the request>,
		 * 											 'signpxy' => <PAGE_NO[X,Y COORDINATES]>,
		 * 											 'description' => <reason for doing esign>,
		 *											 'filename' => <filename will be seen to user>,
		 *											 'rectangle' => <X1, Y1, X2, Y2 coordinates used for placing a sign>,
		 * 											 'file' => <actual file sent for doing a sign>,
		 * 											 'name' => <name of document signer>);
		 */
		$output_arr = array('file_uploaded_id' => '', 'redirect_url' => '', 'api_response' => '');
        $err_flag = 0;                  // err_flag is 0 means no error
        $err_msg = array();             // err_msg stores list of errors found during execution
		extract($input_arr);

		$url = $this->TRUECOPY_DOMAIN .'/services/corpservice/v2/uploadfile';
		$data = [];
		$data['uuid'] = $uuid;
		// preparing hash using API KEY & <Unique ID/Reference ID>
		$data['cs'] = $this->getChksum($this->TRUECOPY_APIKEY, $data['uuid']);
		$data['signpxy'] = $signpxy;
		$data['description'] = $description;
		$data['filename'] = $filename;
		// $data['signmode'] = 2;	// signmode equals 2 means provide signature in image format on document
		$data['uploadfile'] = $file;

		$headers    = array();
		$headers[] = "Accept: application/json";
		$headers[] = "Content-Type: multipart/form-data";
		$response = get_content_by_curl($url, $data, $headers);
		$output_arr['api_response'] = $response;
		if(!empty($response) && json_decode($response) !== FALSE){
			$response = json_decode($response, true);		// parameter TRUE here gives data in an ARRAY format
			if(isset($response['status']) && ($response['status'] == 0) && isset($response['message']) && (strtolower($response['message']) == 'success')){
				$output_arr['file_uploaded_id'] = $data['uuid'];
				// Method 1: Redirecting user to external URL for signing a document
				$output_arr['redirect_url'] = $this->TRUECOPY_DOMAIN .'/corp/v21/esigndocv2.tc?uuid='.$data['uuid'].'&cs='.$data['cs'].'&fn='.str_replace(' ','+',trim($name));
			}
			else{
				// sending error details for further reference
				$err_flag = 1;
				$err_msg_text  = ((isset($response['status']) && !empty($response['status']))?$response['status']:'');
				if(!empty($err_msg_text)){
					$err_msg_text = 'Error Code: '. $err_msg_text .'. ';
				}
				$err_msg_text .= ((isset($response['message']) && !empty($response['message']))?$response['message']:'Unable to proces your request');
				$err_msg[] = $err_msg_text;
				unset($err_msg_text);
			}
		}
		else{
			$err_flag = 1;
			$err_msg[] = 'Unable to upload document for esigning';
		}
		$output_arr['err_flag'] = $err_flag;
		$output_arr['err_msg'] = $err_msg;
		return $output_arr;
	}

	/* Function to prepare the CHECKSUM HASH
	 */
	protected function getChksum($apiKey, $uuid){
		return $this->md5_sum16($apiKey . $uuid);
	}

	/* Function to prepare the MD5 of passed input string but that too only 16 characters long
	 */
	protected function md5_sum16($sData){
		return strtoupper(substr(md5($sData), 0, 16));
	}

	/* Function to handle both SUCCESSFUL & FAILED response retrieved from TRUECOPY website
	 */
	public function download_signed_file($input_arr = array()){
		/* General response parameters received on callback page:
		 * a) uuid: Unique Identifier passed at the time of document signing
		 * b) cs: Checksum
		 * c) status: ESIGNED (when successful aadhaar sign). If the status=ESIGNED proceed to download the signed file
		 * 			  APPROVED (when successful DSC sign).
		 * 			  FAIL (when signing fails). If the status=FAIL retry with the same signer link
		 * d) info: EXACT_MATCH (pdf usecase only).
		 *			MATCH (pdf usecase only).
		 *			MISMATCH (pdf usecase only).
		 *			FAILCODE (pdf usecase only).
		 * e) msg: String message retrieved from an API
		 * f) mi: Name as on Aadhar
		 * g) doc_category: OTHER
		*/

		$output_arr = array('downloaded_file_name' => '', 'uploaded_file_name' => '', 'name_as_per_aadhar' => '');
		$err_flag = 0;                  // err_flag is 0 means no error
		$err_msg = array();             // err_msg stores list of errors found during execution
		/* Sample Response
			_GET data :
			Array
			(
			    [uuid] => <Unique ID/Reference ID>
			    [cs] => <Checksum>
			    [status] => <Status>
			    [info] => <Info sent by vendor>
			    [mi] => <Base64Encoded Name as per aadhar card>
			)
		 */
		if(!isset($input_arr['uuid']) || empty($input_arr['uuid'])){
			$err_flag = 1;
			$err_msg[] = 'Unique ID details not found';
		}

		if(!isset($input_arr['cs']) || empty($input_arr['cs'])){
			$err_flag = 1;
			$err_msg[] = 'Checksum details not found';
		}

		if(!isset($input_arr['request_file_name_prefix']) || empty($input_arr['request_file_name_prefix'])){
			$err_flag = 1;
			$err_msg[] = 'File name not found';
		}

		if($err_flag == 0){
			if(isset($input_arr['status']) && !empty($input_arr['status'])){
				switch($input_arr['status']){
					case 'ESIGNED':
					case 'APPROVED':
						$output_arr['downloaded_file_name'] = $input_arr['request_file_name_prefix'].uniqid('_signed_').'.pdf';
						$file_data = file_get_contents($this->TRUECOPY_DOMAIN .'/services/corpservice/v2/fetchsignedfile/'. $input_arr['uuid'] .'/'. $input_arr['cs'] .'/OTHER');
						file_put_contents(storage_path($input_arr['download_folder_path'] . $output_arr['downloaded_file_name']), $file_data);
						unset($file_data);

						// retrieving name as per aadhar card
						// sample mi value will be like: [{"signername":"<NAME_OF_SIGNER>"}]
						if(isset($input_arr['mi']) && !empty($input_arr['mi']) && base64_decode($input_arr['mi']) !== FALSE){
							$output_arr['name_as_per_aadhar'] = base64_decode($input_arr['mi']);
							if(json_decode($output_arr['name_as_per_aadhar']) !== FALSE){
								$output_arr['name_as_per_aadhar'] = json_decode($output_arr['name_as_per_aadhar'], true);
								$output_arr['name_as_per_aadhar'] = $output_arr['name_as_per_aadhar'][0]['signername'];
							}
						}
						break;
					case 'FAIL':
						$err_flag = 1;
						// $err_msg[] = 'esigning process failed. '.(isset($input_arr['info'])?'. Info: '.$input_arr['info']:'').(isset($input_arr['msg'])?'. Message: '.$input_arr['msg']:'');
						$err_msg[] = 'esigning process failed. '.(isset($input_arr['msg'])?$input_arr['msg']:'');
						break;
				}
			}
		}

		$output_arr['err_flag'] = $err_flag;
		$output_arr['err_msg'] = $err_msg;
		return $output_arr;
	}
}
