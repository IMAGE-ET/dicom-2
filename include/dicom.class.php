<?php
require_once INCLUDE_DIR."orthanc.class.php";

class dicom extends main {

    /**
     * @var object $OT orthanc manipulating class
     */
    var $ot;


    function __construct(){
        parent::__construct();

        $this->ot = new orthanc();
    }


    function parseStudyDate($date){
        $year = substr($date, 0, 4);
        $month = substr($date,4,2);
        $day = substr($date,6,2);
        return "{$day}.{$month}.{$year}";
    }

    function parseStudyTime($time){
        $timeArr = explode(".",$time);
        $hour = substr($timeArr[0],0,2);
        $min = substr($timeArr[0],2,2);
        $sec = substr($timeArr[0],4,2);

        return "{$hour}:{$min}:{$sec}";
    }

    function getPatients()
    {
        $res = $this->ot->getPatientsDbData();
        //var_dump($res);
        $this->smarty->assign("data",$res["table"]);
        $this->smarty->display("main.tpl");
    }

    function getStudyData($pId)
    {
       $data =  $this->ot->getPatientData($pId);
       //$this->smarty->assign("patientData",$data['MainDicomTags']);

        //$study = $this->getCo

       foreach ($data["Studies"] as $key=>$value) {
           $data["Studies"][$key] = $this->ot->getStudyByID($value);

           foreach ($data["Studies"][$key]["Series"] as $key2=>$value2){
               $data["Studies"][$key]["Series"][$key2] = $this->ot->getSeriesData($value2);
           }
       }

//        $this->ot->debug($data);

       $this->smarty->assign_by_ref("studiesData",$data);
       $this->smarty->display("series.tpl");
    }

    function getSeriesData($id,$createVideo=false)
    {
        $seriesData = $this->ot->getSeriesData($id);
        $extension = "jpg";

        //$this->ot->debug($seriesData);

        $modality = $seriesData["MainDicomTags"]["Modality"];

        if (($modality == "CT" || $modality=="MR" || $modality=="PT") && $createVideo){

            $vidData = $this->ot->createVideoFromSeriesInstance($seriesData);

            $this->smarty->assign("video",$vidData["file"]);
            $this->smarty->assign_by_ref("seriesData",$seriesData);
            $this->smarty->display("showserie.tpl");
        }
        else
        {
            $picData = $this->ot->createFilesFromSeriesInstances($seriesData,$extension);
            if (!$picData!=FALSE){
                echo "Error generating pics";
                exit;
            }

            $pictures = array();

            foreach ($seriesData["Instances"] as $instance){
                $pictures[$instance]["bigFile"] = $picData[$instance][$extension]["origFile"];
                $pictures[$instance]["thumbFile"] = $picData[$instance][$extension]["thumbFile"];
            }

            if ($picData!=FALSE)
            {
                $this->smarty->assign_by_ref("picData",$pictures);
                $this->smarty->assign_by_ref("seriesData",$seriesData);
                $this->smarty->display("showserie.tpl");
            }
            else {
                echo "error";
            }
        }
    }


    function queryAndRetrieve1()
    {
        $this->smarty->display("query.tpl");
    }

    function queryAndRetrieve2($data)
    {
        $this->smarty->assign("postData",$data);

        $queryObject = array(
            "Level"=>"Study",
            "Query"=>array(
                "StudyDate"     =>$this->setDicomDate(trim($data["studyDate"])),
            ),
        );

        $queryField = $data["queryField"];

        switch ($queryField){
            case "patientName":
                $queryObject["Query"]["PatientName"] = trim($data["queryText"]);
                break;
            case "patientID":
                $queryObject["Query"]["PatientID"] = trim($data["queryText"]);
                break;
        }


        $res = $this->ot->queryAndRetrieve($queryObject,"TOMOCON");

        $this->ot->debug($res);

        if ($res["status"])
        {
            $this->smarty->assign("QRData",$res["result"]);
        }
        else {
            $this->smarty->assign("Error",$res["result"]);
        }

        $this->smarty->display("query.tpl");
    }




    /**
     * Generates dicom date format correspondig to text...
     * @param string $idf eg. tooday, yesterday etc....
     * @return string $result in dicom format yyyymmdd-
     */

    public function setDicomDate($idf)
    {
        $result="";
        $dt = new DateTime();
        switch ($idf){
            case "today":
                $result=date("Ymd");
                break;
            case "yesterday":
                $tmpD = $dt->modify("-1 day");
                $result = $tmpD->format("Ymd-");
                break;
            case "lastWeek":
                $tmpD = $dt->modify("-7 day");
                $result = $tmpD->format("Ymd-");
                break;
            case "lastMonth":
                $tmpD = $dt->modify("-30 day");
                $result = $tmpD->format("Ymd-");
                break;
            case "anyDate":
                $result="*";
                break;
        }

        return $result;
    }

    function retrieveAllPatients($data)
    {
        $this->ot->moveAllQRPatients($data, "OCLIENT");
    }

    function retrievePatient($data)
    {
        $this->ot->moveQRData($data, "OCLIENT");
    }

    /**
     * Searches Orthanc with formated query
     * 
     * @param array $data
     * @param boolean $returnData - returns search result does not display
     * @param string $modality returns only defined modailty
     * @return mixed|unknown|string[]|mixed[]|boolean[]|string[]
     */
    function searchOrthanc($data,$returnData=FALSE,$modality=NULL)
    {
        
        $modalitySearch = array();
        
        $query="";
        if (isset($data["queryDate"]) && strlen(trim($data["queryDate"]))>0){

            $query = $data["queryDate"];
        }else{
            if (isset($data["query"]))
            {
                if (strpos($data["query"],"*")===false){
                    $query = $data["query"]."*";
                }else{
                    $query = $data["query"];
                }
            }
        }
        
        if (isset($query))
        {
            if (strpos($query," ")!==false){
                $tmp = explode(" ",$query);
                $query = trim($tmp[0])."*";
            }
            
            if (preg_match('/^[0-9]{6}\/[0-9]{4}\*$/', $query)){
                $query = str_replace("bn", "", $query);
                $query = str_replace("/", "", $query);
                $query = str_replace("*", "", $query);
            
                 
                $res = $this->ot->searchOrthancPatientID($query);
                if ($res["status"]){
                    $patientData[] = $res["result"];
                }
            }
            
            
            if (preg_match('/^[0-9]{10}\*$/', $query)){
                $query = str_replace("bn", "", $query);
                $query = str_replace("*", "", $query);
                
               
                $res = $this->ot->searchOrthancPatientID($query);
                if ($res["status"]){
                    $patientData[] = $res["result"];
                }
            }
            if (preg_match('/^[a-zA-Z]+\*$/',$query)){
                $res = $this->ot->searchOrthancPatientName($query);
                if ($res["status"]){
                    $patientData = $res["result"];
                }
            }
            if (preg_match('/^[a-zA-Z]+$/',$query)){
                $res = $this->ot->searchOrthancPatientName("{$query}*");

                if ($res["status"]){
                    $patientData = $res["result"];
                }
            }
            if (preg_match('/^[0-9]{8}$/',$query)){
                $res = $this->ot->searchOrthancBYDate($query);
                
                
                if ($res["status"]){
                    $patientData = $res["result"];
                }
            }
            if (!isset($patientData)){
                $this->smarty->assign("noMatch","Nenajdené žiadne štúdie....");
                $this->smarty->display("series.tpl");
                exit;
            }
            
            
            
            
            if (is_array($patientData) && count($patientData) > 0){

                foreach ($patientData as &$patient){

                    $patient["MainDicomTags"]["PatientName"] = str_replace("^", " ", $patient["MainDicomTags"]["PatientName"]);
                    
                    if (!is_array($patient["Studies"])){
                    	return array("status"=>false,"result"=>"No Patient studies found");
                    }
                    
                    foreach ($patient["Studies"] as &$study){

                        $res = $this->ot->getStudyByID($study);

                        
                        if ($res["status"]===false){
                            return $res;
                        }

                        $study = $res["result"];
                        $study["MainDicomTags"]["StudyDate"] = $this->parseStudyDate($study["MainDicomTags"]["StudyDate"]);
                        $study["MainDicomTags"]["StudyTime"] = $this->parseStudyTime($study["MainDicomTags"]["StudyTime"]);

                        foreach ($study["Series"] as &$serie){
                            $res = $this->ot->getSeriesData($serie);
                            

                            if ($res["status"]===false){
                                return $res;
                            }
                            $serie = $res["result"];
                            
                            
                            if ($modality!=NULL && strpos($serie["MainDicomTags"]["Modality"],$modality)!==FALSE){
                                
                                
                               $modalitySearch[$study["ID"]] = $study;
                            }
                            
                        }
                    }
                }
                if (!$returnData){
                    $this->smarty->assign_by_ref("result",$patientData);
                }
            }else{
                $this->smarty->assign("noMatch","Nenajdené žiadne štúdie....");
            }
            
            if (!$returnData){    
                $this->smarty->display("series.tpl");
            }else{
                if ($modality==NULL){
                    return $patientData;
                }else{
                    return $modalitySearch;
                }
                    
            }
        }
        else {
            if (!$returnData){
                $this->smarty->display("searchP.tpl");
            }else{
                return array("status"=>true,"result"=>"");
            }
        }

    }



    /**
     * Function takes argument filterDate=last_NUMBER_days/months/years and prepares the dicomQuery which then sends to PACS server
     * Beware if you take a wide range it can take a lot of time to download all queries from pacs server....
     * @param string $request example: filterDate=last_3_months (query and retreieves studies from last 3 months)
     */

    function autoQueryRetrieve($request)
    {
        $dicomDate="";
        $dicomTime="";

        if (isset($request["filterDate"])&&!empty($request["filterDate"])){

            $match  = preg_match('/^([a-z]+)_([0-9]{1,2})_([a-z]+)$/',$request["filterDate"],$data);
            if ($match){

                $timeOffset = $data[1];
                $timeCount = $data[2];
                $timeName = $data[3];

                $dt = new DateTime();
                $date = $dt->modify("-{$timeCount} {$timeName}");
                $dicomDate = $date->format("Ymd-");
            }
            else{
                $this->log->logData("Bad filterDate format",false,"Bad filter",true);
                exit;
            }
        }else{
            $dicomDate = date("Ymd-");
        }

       if (isset($request["filterTime"])&&!empty($request["filterTime"])){

            $match  = preg_match('/^([a-z]+)_([0-9]{1,3})_([a-z]+)$/',$request["filterTime"],$data);

            if ($match){

                $timeOffset = $data[1];
                $timeCount = $data[2];
                $timeName = $data[3];

                $dt = new DateTime();
                $date = $dt->modify("-{$timeCount} {$timeName}");
                $dicomTime = $date->format("Hi-");

            }
            else{
                 $this->log->logData("Bad filterTime format",false,"Bad filter",true);
                 exit;
            }
        }

        $dicomQuery = array(
                "Level"=>"Study",
                "Query"=>array("StudyDate"=>$dicomDate,"StudyTime"=>$dicomTime,"PatientName"=>"","ModalitiesInStudy"=>""),
        );


        $result = array();
        $this->log->logData("Starting QR Operation",false);

        
        $res = $this->ot->queryAndRetrieve($dicomQuery, "TOMOCON");

        if ($res["status"]){
            $this->log->logData($result,true,"QR Result");
            $result["queryRetrieveData"] = $res["result"];

            $qrData = array();

            foreach ($res["result"] as $row){

                $qCont = $this->ot->getQueriesContent($row["Path"], $row["retrieveID"]);
                if ($qCont["status"]===false){
                }
                $qrData[]=array(
                    "path"  =>$row["Path"],
                    "rId"   =>$row["retrieveID"],
                    "content" =>$qCont["result"],
                );
            }
            $result["queryRetrieveData"]=$qrData;
            foreach ($qrData as $qrRow){
                //var_dump($qrRow);
                $result["moveData"][]= $this->ot->moveQRData($qrRow, "OSIRKDCH");
            }

            $this->log->logData($result,false,"MOVE OK");
            echo "OK";
            exit;
            //return array("status"=>true,"result"=>$result);
        }
        else{
             $this->log->logData($res["result"],false,"Error in QR OP",true);
            //return array("status"=>false,"result"=>$res["result"]);
        }
    }


    public function searchForm($data){
        $this->smarty->display("searchP.tpl");
    }

    function runAsync($data)
    {
        $this->commJs->getRespond($data["data"], "rjson");
    }


    function delStudies($data){

        $match  = preg_match('/^([a-z]+)_([0-9]{1,3})_([a-z]+)$/',$data["filterDate"],$parsed);

        if ($match){
            $timeOffset =   $parsed[1];
            $timeCount =    $parsed[2];
            $timeName =     $parsed[3];

            $dt = new DateTime();
            $date = $dt->modify("-{$timeCount} {$timeName}");
            $now = date("Ymd");
            $dicomDate = $date->format("-Ymd");
        }else{
            $this->log->logData("Bad filterDate format",false,"Bad filter",true);
            exit;
        }

        $query = array("Level"=>"Study","Query"=>array("StudyDate"=>$dicomDate));

        $res = $this->ot->getStudiesByDate($query);

        if ($res["status"]){
            $studies = $res["result"];

            if (isset($data["dryRun"]) && intval($data["dryRun"]==0)){

                foreach ($studies as $study)
                {
                    $url  = $this->url."/studies/{$study}";
                    $res1 = $this->ot->_c_delete($url, $data);

                    if ($res1["status"]===false){
                        $this->log->logData($data,false,"Error in CURL delete",true);
                        exit;
                    }
                }
            }else{
                echo "Dry Run...";
                var_dump($res);
                exit;
            }
        }
        else{
            $this->log->logData($res,false,"Error getting studies",true);
        }
        echo "OK";
        $this->log->logData("Delete completed sucsesfully",false,"",false);
    }


    function toDay(){
        
        $data = array("queryDate"=>date("Ymd"));
        $res = $this->searchOrthanc($data);
    }

    function toDayXA(){
        $data = array("StudyDate"=>date("Ymd"),"Modality"=>"XA");
        $res = $this->ot->searchOrthancByStudy($data);
        $this->displaySearchResStudy("Dnes XA", $res);
    }

    function todayCR(){
        $data = array("StudyDate"=>date("Ymd"),"Modality"=>"CR");
        $res = $this->ot->searchOrthancByStudy($data);

        $this->displaySearchResStudy("Dnes RTG", $res);
    }
    
    function lastHour(){
    	
    	$dt = new DateTime();
    	
    	$today = $dt->format("Ymd");
    	$now = $dt->format("Hi00");
    	$lastHour = $dt->modify("-3 hour");
    	$lhStr = $lastHour->format("Hi00-");
    	
    	$data = array("StudyDate"=>$today,"StudyTime"=>$lhStr.$now,"Modality"=>"CR");
    	
    	$res = $this->ot->searchOrthancByStudy($data);
    	
    	return $res;
    	
    	    	
    }
    
    
    function todayCT(){
        $data = array("StudyDate"=>date("Ymd"),"Modality"=>"CT");
        
        $res=$this->ot->searchOrthancByStudy($data);
        $this->displaySearchResStudy("Dnešné CT", $res);
    }

    function yesterdayXA(){
        $dt = new DateTime();
        $yesterday = $dt->modify("-1 day");
        $yStr = $yesterday->format("Ymd");
        $data = array("StudyDate"=>$yStr,"Modality"=>"XA");
        
        $res = $this->ot->searchOrthancByStudy($data);
        $this->displaySearchResStudy("Vcerajsie XA", $res);
    }

    /*function lastHour()
    {
        $dt = new DateTime();
        $hourDt = $dt->modify("-1 hour");
        $hour = $dt->format("Hi-");
        $today = date("Ymd");
        $data = array("queryDate"=>$today,"dicomTime"=>$hour);
        $data["parameter"] = "Posledná hodina";

        $this->searchByDateTimeModality($data,"ALL");
    }*/
    
    
    function displaySearchRes($parameter,$result)
    {
    	
        $this->smarty->assign("parameter",$parameter);
        $this->smarty->assign("result",$result);
        
        $this->smarty->display("searchRes.tpl");
    }
    
    
    function displaySearchResStudy($parameter,$result)
    {
    	 
    	if (count($result["result"])==0){
    		$this->smarty->assign("noMatch","Nenajdene ziadne studie....");
    		$this->smarty->display("series.tpl");
    	}else{
    		$this->smarty->assign("parameter",$parameter);
    		$this->smarty->assign("result",$result["result"]);
    
    		$this->smarty->display("searchResStudy.tpl");
    	}
    }
    
    
    function checkDbForPics($series)
    {
        $sql  = "SELECT COUNT([series_UUID]) AS [rows] FROM [pic_data] WHERE [series_uuid]={series|s}";

        $rep = array("series"=>$series);
        
        $sql=$this->db->buildSql($sql,$rep);
        
        $res = $this->db->row($sql);
        
        return $res;
        
    }
    
    
    function showViewer($data){
        
        $pics = $this->checkDbForPics($data["seriesUUID"]);
        
        if (intval($pics["rows"]) > 0) {
            
            $this->smarty->assign("series",$data["seriesUUID"]);
            $this->smarty->assign("cache","1");
            $this->smarty->display("mviewer.tpl");
            
        }
        else{
            $res1 = $this->ot->getSeriesData($data["seriesUUID"]);
            
            if ($res1["status"]==FALSE){
                $this->showErrorMsg($res1["result"]);
            }
            
            $res = $this->ot->createFilesFromSeriesInstances($res1["result"]);
            //  var_dump($res);
            
            if ($res){
            
                $dt = new DateTime();
                $expDt = $dt->modify("+3 month");
                $expireDate = $expDt->format("Y-m-d");
            
                $dbRes = $this->saveDataToDb($res, $data["seriesUUID"], $expireDate);
            
                if ($dbRes["status"]==false){
                    $this->showErrorMsg($dbRes["result"]);
                }
            
            
                $this->smarty->assign("series",$data["seriesUUID"]);
                $this->smarty->display("mviewer.tpl");
            }else{
                $this->showErrorMsg("No pictures created.....");
            } 
        }
        
    }
    
    function saveDataToDb($data,$seriesUUID, $expireDate)
    {
        $dataLn = count($data);
            
        for ($d=1;$d<=$dataLn;$d++)
        {
        
            $saveData[] = array(
                
                "series_uuid"=>$seriesUUID,
                "instance_uuid"=>$data[$d]["instance"],
                "file_location"=>$data[$d]["file"],
                "file_info"=>serialize($data[$d]["info"]),
                "order" =>$data[$d]["order"],
                "expire" => $expireDate,
                //"series_instance_uid"=>$data[$d]["SeriesInstanceUID"],
            );
        }
            
            $res = $this->db->insert_rows("pic_data",$saveData);
            
            return $res;
    }
    
    function pacs($request){
        
        
        //var_dump($request);
        
        $query = array("Level"=>"Study","Query"=>array("StudyDate"=>"20160627","PatientName"=>"","StudyDescription"=>"","ModalitiesInStudy"=>""));
        
        $this->smarty->display("pacs.tpl");
        
        //$data = $this->ot->queryAndRetrieve($query, "TOMOCON");
        
    }
    
    function searchPacs($request)
    {
    	
    	
    	if (!array_key_exists("queryType",$request)){
    		$this->smarty->assign("errorMsg","No query type given....");
    		$this->smarty->display("pacs.tpl");
    		 
    		return;
    	}
    	
    	
    	if (!array_key_exists("query", $request)){
    		
    		$this->smarty->assign("errorMsg","No query data given....");
    		$this->smarty->display("pacs.tpl");
    		 
    		return;
    	}
    	
    	
        if (strlen(trim($request["query"])) == 0){
        	$this->smarty->assign("errorMsg","No query data given....");
        	$this->smarty->display("pacs.tpl");
        	
        	return;
        }
        
    	$query = array("Level"=>"Study","Query"=>array("PatientName"=>"","ModalitiesInStudy"=>"","StudyDate"=>"","PatientID"=>""));
    	
    	switch( $request["queryType"] ){
    		
    		
    		case "name":
    			
    			$request["query"] = str_replace(array("\\","/"), array("",""), $request["query"]);
    			
    			if (preg_match('/^[a-zA-Z]+/',$request["query"])) {
    				$query["Query"]["PatientName"] = $request["query"]."*";
    			}
    			else{
    				$this->smarty->assign("errorMsg","Name must contain only letters...");
    				$this->smarty->display("pacs.tpl");
    				 
    				return;
    			}
    			
    			break;
    		case "binNum":
    			if (preg_match("/[0-9]+/",$request["query"]) ){
    				
    				$query["Query"]["PatientID"] = $request["query"];
    			
    			}else{
    				
    				$this->smarty->assign("errorMsg","No valid bin number....");
    				$this->smarty->display("pacs.tpl");
    					
    				return;
    			}
    			
    			break;
    		
    	}
        
        if (isset($request["queryDate"]) && !empty($request["queryDate"])){
            $query["Query"]["StudyDate"] = $request["queryDate"];
        }
        
        $data = $this->ot->queryAndRetrieve($query, "TOMOCON");
        
        //var_dump($data);
        
       // exit;
        
        if ($data["status"] === FALSE){
        	$this->smarty->assign("errorMsg",$data["result"]);
        	$this->smarty->display("pacs.tpl");
        	return;
        	
        }
        
        
        foreach ($data["result"] as &$row){
            $row["StudyDate"] = $this->parseStudyDate($row["StudyDate"]);
        }
        
        $this->smarty->assign("data",$data["result"]);
        $this->smarty->display("pacs_res.tpl");
        
        
    }
    
    
    

}
?>