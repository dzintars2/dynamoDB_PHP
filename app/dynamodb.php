<?php
/*
*/
require __DIR__.'/../vendor/autoload.php';
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
date_default_timezone_set('UTC');


//darbība kura tiek izsaukta no index.html
if (isset($_POST["darbiba"])){
	switch($_POST["darbiba"]){
		case "generetDatus":
			generateData();
			break;
		case "createTable":
			createTable("uznemumi","regNo");
			createTable("preces","itemNumber");
			createTable("pasutijumi","numurs");
			break;
		case "report1":
			report1();
			break;
		case "report2":
			report2();
			break;
		case "report3":
			report3();
			break;
		case "report4":
			report4();
			break;
		case "report5":
			report5();
			break;
		case "report6":
			report6();
			break;
		default:
			break;
	}
}

//jaunas tabulas izveidošana - tabulas nosaukums, primārā atslēga (key)
function createTable($tableName, $primaryKey){
	$sdk = new Aws\Sdk([
    	//'region'   => 'eu-central-1',
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$params = [
	    'TableName' => $tableName,
	    'KeySchema' => [
	        [
	            'AttributeName' => $primaryKey,
	            'KeyType' => 'HASH'  //Partition key
	        ]
	    ],
	    'AttributeDefinitions' => [
	        [
	            'AttributeName' => $primaryKey,
	            'AttributeType' => 'S'
	        ],

	    ],
	    'ProvisionedThroughput' => [
	        'ReadCapacityUnits' => 5,
	        'WriteCapacityUnits' => 5
	    ]
	];

	try {
	    $result = $dynamodb->createTable($params);
	    echo 'Izveidota tabula "'.$tableName.'". Statuss: ' . 
	        $result['TableDescription']['TableStatus'] ."\n";
	} catch (DynamoDbException $e) {
	    echo "Neizdevās izveidot tabulu:\n";
	    echo $e->getMessage() . "\n";
	}
}


//datu ģenerēšana. Datu avots data.json
//no faila tiek ielādēti uzņēmumi un preces, bet pasūtījumu ieraksti tiek ģenerēti (500 ieraksti)
//atkārtoti izsaucot ģenerēšanu tiek atjaunināti uzņēmumu un preču klasifikatori un ģenerēti jauni 500 ieraksti
function generateData(){	
	//json datne ar klasifikatoriem
	$uzcenojums = 0.4; //fiksēts uzcenojums
	$jsonData = json_decode(file_get_contents('data.json'), true);
	$uznemumi = $jsonData["companyList"];
	jsonToDb("uznemumi", $uznemumi); //ielādēju uzņēmumu klasifikatoru DB
	$preces = $jsonData["priceList"];
	jsonToDb("preces",$preces);

	//izveidoju random ierakstus.
	$pasutijumi = array();
	for ($i=0; $i<500; $i++){
		$preceId = rand(0, sizeof($preces)-1);
		$skaits = rand(1,20);
		$pasutijumi[$i] = array(
				"numurs" => $i.rand(1000,10000),
				"datums" => date('Y-m-d', strtotime( '-'.mt_rand(0,300).' days')),
				"uznemums" => $uznemumi[rand(0, sizeof($uznemumi)-1)]["regNo"],
				"prece" => $preces[$preceId]["itemNumber"],
				"skaits" => $skaits,
				"cena" => $preces[$preceId]["price"],
				"summa" => $preces[$preceId]["price"]*$skaits,
				"pasizmaksa" => round((($preces[$preceId]["price"]*$skaits)/(1+$uzcenojums)),2)
			);
	}
	jsonToDb("pasutijumi",$pasutijumi);
}


//datu ielāde datubāzē
//padodam tabulas nosaukumu un datu masīvu
function jsonToDb($tableName, $jsonData){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$skaits = 0;
	$kludas = 0;
	for ($i=0; $i<sizeOf($jsonData);$i++){
		$json = json_encode($jsonData[$i]);
		$params = [
	        'TableName' => $tableName,
	        'Item' => $marshaler->marshalJson($json)
	    ];
	    try {
	        $result = $dynamodb->putItem($params);
	        $skaits++;
	    } catch (DynamoDbException $e) {
	    	$kludas++;
	        echo "Neizdevās pievienot ierakstu:\n";
	        echo $e->getMessage() . "\n";
	        break;
	    }
	}
	echo "Tabula: '" .$tableName .  "' Pievienoti/ataunināti: ".$skaits." ieraksti\n";
	if ($kludas>0) echo "Neizdevās apstrādāt: ".$kludas." ierakstus\n";
}




//1.atskaite: pasūtījumi par summu virs 300 EUR, papildus tiek atrasts uzņēmuma nosaukums
function report1(){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":summa": 250,
			":datums": "2018-01-01"
    	}
	');

	$params = [
    	'TableName' => 'pasutijumi',
    	'ProjectionExpression' => 'datums, numurs, uznemums, summa, prece ',
    	'FilterExpression' => '#summa >= :summa AND #datums >= :datums',
    	'ExpressionAttributeNames'=> [ '#summa' => 'summa', '#datums' => 'datums'  ],
    	'ExpressionAttributeValues'=> $eav
	];
	$result = $dynamodb->scan($params);
	$pasutijumi = array();
	$z=0;
	try {
   		while (true) {
	        
	        foreach ($result['Items'] as $i) {
	           $pasutijums = $marshaler->unmarshalItem($i);
	           $pasutijumi[$z] = $pasutijums;
	           $pasutijumi[$z]["summa"] = number_format($pasutijumi[$z]["summa"], 2, '.', '');
	           $pasutijumi[$z]["uznemums"] = getCompanyName($pasutijums["uznemums"]); //tabulā uznemumi atrodam uznemuma nosaukumu pēc reģistrācijas numura 
	           $pasutijumi[$z]["prece"] = getItemByNumber($pasutijums["prece"]);
	           $z++;
	        }

	        //ja nākamais ieraksts = pēdējais ieraksts, tad ejam ārā
	        if (isset($result['LastEvaluatedKey'])) {
	            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
	        } else {
	            break;
	        }
	    }

	} catch (DynamoDbException $e) {
	    echo "Kļūda datu apstrādē:\n";
	    echo $e->getMessage() . "\n";
	}
	usort($pasutijumi, function($a, $b) {
	    return $b['summa'] <=> $a['summa'];
	});
	exit(json_encode($pasutijumi));
}


//2.atskaite - saraksts ar visiem uzņēmumiem, kuri reģistrēti Rīgā
function report2(){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":pilseta": "Rīga"
    	}
	');

	$params = [
    	'TableName' => 'uznemumi',
    	'ProjectionExpression' => 'companyName, regNo, address',
    	'FilterExpression' => 'contains(#pilseta, :pilseta)',
    	'ExpressionAttributeNames'=> [ '#pilseta' => 'address' ],
    	'ExpressionAttributeValues'=> $eav
	];
	$result = $dynamodb->scan($params);
	$uznemumi = array();
	$z=0;
	try {
   		while (true) {
	        
	        foreach ($result['Items'] as $i) {
	           $uznemums = $marshaler->unmarshalItem($i);
	           //pārbaudam vai ir bijušu darījumi
	           $eav = $marshaler->marshalJson('
			    	{
			        	":regNo": "'.$uznemums["regNo"].'",
			        	":datums": "2018-01-01",
			        	":prece": "001"
			    	}
				');
	           $params = [
			    	'TableName' => 'pasutijumi',
			    	'Select' => 'COUNT',
			    	'FilterExpression' => '#uznemums = :regNo AND #datums >= :datums AND contains(#prece,:prece)',
			    	'ExpressionAttributeNames'=> [ '#uznemums' => 'uznemums', '#datums' => 'datums', '#prece' => 'prece' ],
			    	'ExpressionAttributeValues'=> $eav
				];
				$result2 = $dynamodb->scan($params);
				if ($result2["Count"]>10){
					$uznemumi[$z] = $uznemums; 
					$uznemumi[$z]["pasutijumu_skaits"] = $result2["Count"];
	           		$z++;
				}
	        }

	        //ja nākamais ieraksts = pēdējais ieraksts, tad ejam ārā
	        if (isset($result['LastEvaluatedKey'])) {
	            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
	        } else {
	            break;
	        }
	    }

	} catch (DynamoDbException $e) {
	    echo "Kļūda datu apstrādē:\n";
	    echo $e->getMessage() . "\n";
	}
	usort($uznemumi, function($a, $b) {
	    return $b['pasutijumu_skaits'] <=> $a['pasutijumu_skaits'];
	});
	exit(json_encode($uznemumi));

}

//3.atskaite - ieņēmumi un peļņa pa mēnešiem
function report3(){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);

	$itemName = "pildspalva";
	$preces = getItemByName($itemName);
	if (sizeof($preces)==0){
		//nav atrastas tādas preces
		exit("Šādas preces nav atrastas");
	}
	$precesList = array();
	for ($i=0;$i<sizeof($preces);$i++){
		$precesList[$i] = $preces[$i]["itemNumber"];
	}

	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":datums": "2018-01-01",
        	":uznemums": "90000076669"
    	}
	');
	$eav[":itemNumber"] = $marshaler->marshalValue($precesList);

	$params = [
    	'TableName' => 'pasutijumi',
    	'ProjectionExpression' => 'datums, summa, pasizmaksa ',
    	'FilterExpression' => '#datums >= :datums AND #uznemums<>:uznemums AND contains(:itemNumber,#itemNumber)',
    	'ExpressionAttributeNames'=> [ '#datums' => 'datums', '#uznemums'=>'uznemums', '#itemNumber' => 'prece' ],
    	'ExpressionAttributeValues'=> $eav
	];
	$result = $dynamodb->scan($params);
	$finansuRaditaji = array(
						array("menesis"=>"janvāris","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"februāris","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"marts","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"aprīlis","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"maijs","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"jūnijs","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"jūlijs","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"augusts","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"septembris","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"oktobris","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"novembris","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"decembris","ienemumi"=>0, "pelna"=>0),
						array("menesis"=>"<b>kopā</b>","ienemumi"=>0, "pelna"=>0)
					);
	try {
   		while (true) {
	        
	        foreach ($result['Items'] as $i) {
	           $pasutijums = $marshaler->unmarshalItem($i);
	           $menesis = substr($pasutijums["datums"],5,2);
	           $finansuRaditaji[$menesis-1]["ienemumi"] += $pasutijums["summa"];
	           $finansuRaditaji[$menesis-1]["pelna"] += $pasutijums["summa"]-$pasutijums["pasizmaksa"];
	        }

	        //ja nākamais ieraksts = pēdējais ieraksts, tad ejam ārā
	        if (isset($result['LastEvaluatedKey'])) {
	            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
	        } else {
	            break;
	        }
	    }

	} catch (DynamoDbException $e) {
	    echo "Kļūda datu apstrādē:\n";
	    echo $e->getMessage() . "\n";
	}
	//noformatēju izejas datus
	for ($i=0; $i<sizeof($finansuRaditaji);$i++){
		$finansuRaditaji[$i]["ienemumi"] = number_format($finansuRaditaji[$i]["ienemumi"], 2, '.', '');
		$finansuRaditaji[$i]["pelna"] = number_format($finansuRaditaji[$i]["pelna"], 2, '.', '');
	}
	usort($finansuRaditaji, function($a, $b) {
	    return $b['ienemumi'] <=> $a['ienemumi'];
	});
	for ($i=sizeof($finansuRaditaji); $i>0;$i--){
		//print(key($skaits[$i]));
		if ($i>=3) unset($finansuRaditaji[$i]);//=array();
	}
	exit(json_encode($finansuRaditaji));
}

//4.atskaite - atgriež pasūtījumus, kuros ir pirkta prece, kuras nosaukums satur noteiktu tekstu. 
//Noklusētā vērtība "Papīrs" arī Atskaites piemērā
function report4($itemName="Papīrs",$dateFrom="2018-08-01"){
	$preces = getItemByName($itemName);
	if (sizeof($preces)==0){
		//nav atrastas tādas preces
		exit("Šādas preces nav atrastas");
	}

	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$precesList = array();
	for ($i=0;$i<sizeof($preces);$i++){
		$precesList[$i] = $preces[$i]["itemNumber"];
		//$precesList .= $i>0 ? ',"'.$preces[$i]["itemNumber"].'"' : '"'.$preces[$i]["itemNumber"].'"'; 
	}
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();

	$params = [
    	'TableName' => 'pasutijumi',
    	'ProjectionExpression' => 'datums, numurs, uznemums, prece, skaits, cena, summa ',
    	'FilterExpression' => 'contains(:itemNumber,#itemNumber) AND #datums >= :datums',
    	'ExpressionAttributeNames'=> [ '#itemNumber' => 'prece', "#datums" => 'datums' ],
    	'ExpressionAttributeValues'=> [':itemNumber' => $marshaler->marshalValue($precesList), ':datums'=>$marshaler->marshalValue($dateFrom)]
	];
	$result = $dynamodb->scan($params);
	$pasutijumi = array();
	$z=0;
	try {
   		while (true) {
	        
	        foreach ($result['Items'] as $i) {
	           $pasutijums = $marshaler->unmarshalItem($i);
	           if ($pasutijums["skaits"]>=5){
	           	   $pasutijumi[$z] = $pasutijums;
		           $pasutijumi[$z]["summa"] = number_format($pasutijumi[$z]["summa"], 2, '.', '');
		           $pasutijumi[$z]["uznemums"] = getCompanyName($pasutijums["uznemums"]); //tabulā uznemumi atrodam uznemuma nosaukumu pēc reģistrācijas numura 
		           $pasutijumi[$z]["precesNosaukums"] = getItemByNumber($pasutijums["prece"]);
		           $z++;
	           }

	        }

	        //ja nākamais ieraksts = pēdējais ieraksts, tad ejam ārā
	        if (isset($result['LastEvaluatedKey'])) {
	            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
	        } else {
	            break;
	        }
	    }

	} catch (DynamoDbException $e) {
	    echo "Kļūda datu apstrādē:\n";
	    echo $e->getMessage() . "\n";
	}
	usort($pasutijumi, function($a, $b) {
	    return $b['datums'] <=> $a['datums'];
	});
	exit(json_encode($pasutijumi));
}

//5.atskaite - 3 lielākie pircēji šogad
function report5(){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":datums": "2018-01-01"
    	}
	');

	$params = [
    	'TableName' => 'pasutijumi',
    	'ProjectionExpression' => 'summa, uznemums ',
    	'FilterExpression' => '#datums >= :datums',
    	'ExpressionAttributeNames'=> [ '#datums' => 'datums' ],
    	'ExpressionAttributeValues'=> $eav
	];
	$result = $dynamodb->scan($params);
	$pardosana = array();	

	try {
   		while (true) {
	        
	        foreach ($result['Items'] as $i) {
	           $pasutijums = $marshaler->unmarshalItem($i);
	           if (!isset($pardosana["u".$pasutijums["uznemums"]]["uznemums"])){
	           		$pardosana["u".$pasutijums["uznemums"]]["uznemums"] = getCompanyName($pasutijums["uznemums"]);
	           		$pardosana["u".$pasutijums["uznemums"]]["summa"] = $pasutijums["summa"];
	           } else {
	           		$pardosana["u".$pasutijums["uznemums"]]["summa"] += $pasutijums["summa"];
	           }
	        }

	        //ja nākamais ieraksts = pēdējais ieraksts, tad ejam ārā
	        if (isset($result['LastEvaluatedKey'])) {
	            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
	        } else {
	            break;
	        }
	    }

	} catch (DynamoDbException $e) {
	    echo "Kļūda datu apstrādē:\n";
	    echo $e->getMessage() . "\n";
	}
	usort($pardosana, function($a, $b) {
	    return $b['summa'] <=> $a['summa'];
	});
	for ($i=sizeof($pardosana); $i>0;$i--){
		//print(key($skaits[$i]));
		if ($i>=3) unset($pardosana[$i]);//=array();
	}
	for ($i=0; $i<sizeof($pardosana);$i++){
		$pardosana[$i]["summa"] = number_format($pardosana[$i]["summa"], 2, '.', '');
	}
	exit(json_encode($pardosana));
}

function report6(){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":datums": "2018-01-01"
    	}
	');
	$params = [
    	'TableName' => 'pasutijumi',
    	'ProjectionExpression' => 'daudzums, prece ',
    	'FilterExpression' => '#datums >= :datums',
    	'ExpressionAttributeNames'=> [ '#datums' => 'datums' ],
    	'ExpressionAttributeValues'=> $eav
	];

	$result = $dynamodb->scan($params);
	$skaits = array();	
	$preces = array();
	try {
   		while (true) {
	        
	        foreach ($result['Items'] as $i) {

	           $pardosana = $marshaler->unmarshalItem($i);
	           if (!isset($skaits[$pardosana["prece"]])) {
	           		$skaits[$pardosana["prece"]]["preces_kods"] = $pardosana["prece"];
	           		$skaits[$pardosana["prece"]]["daudzums"] = 1;
	           		$skaits[$pardosana["prece"]]["nosaukums"] = getItemByNumber($pardosana["prece"]); 
	           } else {
	           		$skaits[$pardosana["prece"]]["daudzums"]++;
	           }
	        }

	        //ja nākamais ieraksts = pēdējais ieraksts, tad ejam ārā
	        if (isset($result['LastEvaluatedKey'])) {
	            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
	        } else {
	            break;
	        }
	    }

	} catch (DynamoDbException $e) {
	    echo "Kļūda datu apstrādē:\n";
	    echo $e->getMessage() . "\n";
	}
	usort($skaits, function($a, $b) {
	    return $b['daudzums'] <=> $a['daudzums'];
	});
	for ($i=sizeof($skaits); $i>0;$i--){
		//print(key($skaits[$i]));
		if ($i>=3) unset($skaits[$i]);//=array();
	}

	exit(json_encode($skaits));


}

//Papildus darbības
//Papildus funckija atgriež uzņēmuma nosaukumu pēc reģistrācijas numura
function getCompanyName($regNo){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":regNo": "'.$regNo.'"
       	}
	');

	$params = [
    	'TableName' => 'uznemumi',
    	'ProjectionExpression' => 'companyName',
    	'FilterExpression' => '#regNo = :regNo',
    	'ExpressionAttributeNames'=> [ '#regNo' => 'regNo' ],
    	'ExpressionAttributeValues'=> $eav
	];
	$result = $dynamodb->scan($params);
	try{
		$uznemums = $marshaler->unmarshalItem($result["Items"][0]);
		return $uznemums["companyName"];
	} catch (DynamoDbException $e) {
	    return $regNo; //kļūda, atgriežam atpakaļ reģistrācijas numuru
	}
}

//Papildus funkcija atgriež preces nosaukumu pēc koda
function getItemByNumber($itemNo){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":itemNo": "'.$itemNo.'"
       	}
	');

	$params = [
    	'TableName' => 'preces',
    	'ProjectionExpression' => 'itemName',
    	'FilterExpression' => '#itemNo = :itemNo',
    	'ExpressionAttributeNames'=> [ '#itemNo' => 'itemNumber' ],
    	'ExpressionAttributeValues'=> $eav
	];
	$result = $dynamodb->scan($params);
	try{
		$prece = $marshaler->unmarshalItem($result["Items"][0]);
		return $prece["itemName"];
	} catch (DynamoDbException $e) {
	    return $itemNo; //kļūda, atgriežam atpakaļ preces kodu
	}
}


//atrodam preces pēc nosaukuma daļas
function getItemByName($itemName){
	$sdk = new Aws\Sdk([
    	'region'   => 'local',
   	 	'version'  => 'latest',
   	 	'endpoint' => 'http://localhost:8000'
	]);
	$dynamodb = $sdk->createDynamoDb();
	$marshaler = new Marshaler();
	$eav = $marshaler->marshalJson('
    	{
        	":itemName": "'.$itemName.'"
       	}
	');

	$params = [
    	'TableName' => 'preces',
    	'ProjectionExpression' => 'itemNumber, #itemName',
    	'FilterExpression' => 'contains(#itemName, :itemName)',
    	'ExpressionAttributeNames'=> [ '#itemName' => 'itemName' ],
    	'ExpressionAttributeValues'=> $eav
	];
	$result = $dynamodb->scan($params);
	$preces = array();
	try{
		/*$prece = $marshaler->unmarshalItem($result["Items"][0]);
		return $prece["name"];*/
		$z=0;
		while (true) {  
	        foreach ($result['Items'] as $i) {
	           $preces[$z++] = $marshaler->unmarshalItem($i);
	        }
	        //ja nākamais ieraksts = pēdējais ieraksts, tad ejam ārā
	        if (isset($result['LastEvaluatedKey'])) {
	            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
	        } else {
	            break;
	        }
	    }
	    return $preces;
	} catch (DynamoDbException $e) {
	    return $preces; //kļūda
	}
}