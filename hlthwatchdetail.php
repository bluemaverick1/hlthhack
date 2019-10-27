<?php

cors();

function cors() {

    // Allow from any origin
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        // Decide if the origin in $_SERVER['HTTP_ORIGIN'] is one
        // you want to allow, and if so:
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');    // cache for 1 day
    }

    // Access-Control headers are received during OPTIONS requests
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
            // may also be using PUT, PATCH, HEAD etc
            header("Access-Control-Allow-Methods: GET, POST, OPTIONS");         

        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
            header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

        exit(0);
    }

}

session_start(); // Starting Session
// Storing Session
require 'globalserver.php';
require 'globaljavaserver.php';
require 'globalfunctions.php';
require __DIR__ . '/twilio-php-master/Twilio/autoload.php';
require __DIR__ . '/vendor/autoload.php';
// require __DIR__ . '/agora/src/DynamicKey5.php';
//require __DIR__ . '/agora/src/SimpleTokenBuilder.php';

use \Firebase\JWT\JWT;


function CallAPI($method, $url, $data = false)
{
    $curl = curl_init();
    

    switch ($method)
    {
        case "POST":
            curl_setopt($curl, CURLOPT_POST, 1);

            if ($data)
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_PUT, 1);
            break;
        default:
            if ($data)
                $url = sprintf("%s?%s", $url, http_build_query($data));
    }

    // Optional Authentication:
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl, CURLOPT_USERPWD, "username:password");

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    

    $auth = "Bearer " . $GLOBALS['hlthtoken'];
$headers = [
    'Content-Type: application/fhir+json; charset=utf-8',
    'Connection: Keep-Alive',
    'Authorization:' . $auth,
    
];

curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($curl);

    curl_close($curl);

    return $result;
}


$url = "https://watchoverme.azurehealthcareapis.com/Patient/09d2f66d-231a-4ab8-9fbf-86cbe176b7b8";
$res = CallAPI("GET", $url);
    
$url = "https://watchoverme.azurehealthcareapis.com/Appointment";
$bres = CallAPI("GET", $url);



$finalres = $res . "<br><br><br>" . $bres;

//$decoded = JWT::decode($jwt, $key, array('HS256'));

//print_r($decoded);


if (!isset($_SESSION['username']) || (isset($_SESSION['username']) && $_SESSION['username'] === "guest") ) {
    header("LOCATION: registration.php");
}


$viewerid = $_SESSION['userid'];
$viewercon = connectdb("normal", shr($viewerid));
$numshowattendees = 0;

if(isset($_GET['room'])){
    $room = outclean($_GET['room']);
}
else{
    $room = "default";
}

$row = getuserinfo($viewerid);
$vsn = outclean($row['screenname']);

$showcon = connectdb('normal', shr($room));
$ispreviewing = "no";
$badpayments = "";

function startsWith($haystack, $needle)
{
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
}
        
// first check if room is within scheduled time
// if so, allow viewers on show list to enter room
// when creator enters charge everyone on show attendee list
// if somone buys a ticket to show after creator has already started, charge them seperately when they enter


$sql = "SELECT * FROM creator_shows where channel=? AND (showstatus='startedwithhost' OR (CURRENT_TIME() >= begintime AND CURRENT_TIME() <= endtime))";
$result = querye($showcon, $sql, $room);
if ($row = mysqli_fetch_array($result)) {
    $showstatus = $row['showstatus'];
    
    if($showstatus == "canceled"){
        echo "show has been canceled";
        exit();
    }

    if($showstatus == "completed"){
        echo "show has been completed already";
        exit();
    }
    
    $creatorid = $row['creatorid'];
    $chattopic = outclean($row['description']);
    $viewsetting = outclean($row['type']);
    $channel = $row['channel'];
    $begindate = $row['date'];
    $category = outclean($row['category']);
    $foo = strtotime($row['endtime']);
    $bar = strtotime($row['begintime']);
    $totalamountsold = $row['totalamountsold'];
    $targetprice = $row['targetprice'];
    $numshowattendees = $row['numsold'];
    
    $duration = $foo - $bar;
    $duration = $duration;
    
    if($creatorid == $viewerid){
        $userrole = "host";
        $pricepaid = "";
        if($showstatus == "waiting"){
            // check if host entered room with 15 minutes
            $time = strtotime($begindate);
            $lasttime = $time + (15*60);
            $curtime = time();
            if($curtime >= $lasttime){
                echo "it is past 15 minutes since start time, show has been canceled";
                exit();
            }
            
            if($viewsetting === "broadcast"){
                if($totalamountsold < $targetprice){
                    echo "show has not reached funding goal to start, only $" . $totalamountsold . " of goal $" . $targetprice;
                    exit();
                }
            }
                                     
            $sql = "SELECT * FROM show_attendees where channel=?";
            $zresult = querye($showcon, $sql, $room);
            while ($zrow = mysqli_fetch_array($zresult)) {
                $userstatus = $zrow['userstatus'];
                $fid = $zrow['fid'];
                $curaten = $zrow['userid'];
                $curpid = $zrow['PID'];
                        
                // check if payment needs to be processed
                if(empty($fid)){
                    $rid = $zrow['rid'];

                    $sql = "SELECT * FROM request_transact WHERE rid=?" ;
                    $bresult = querye($showcon, $sql, $rid);
                    if($brow = mysqli_fetch_array($bresult)){
                        $paymenttype = $brow['paymenttype'];
                        $source = $brow['source'];
                        $requestorid = $brow['requestorid'];
                        $date = $brow['date'];
                        $amount = $brow['price'];
                        $amount = intval($amount *100);

                        $creationtime = date('Y-m-d H:i:s');
                        $requestorcon = connectdb("normal", shr($requestorid));

                    }
                    
                    // double check to make sure requestor has not already paid, by placing lock
                        $creatorcon = connectdb("normal", shr($creatorid));
                        begin_transaction($creatorcon);
                                
                        
                        $sql = "SELECT * FROM show_attendees where PID=? FOR UPDATE";
                        $yodaresult = querye($creatorcon, $sql, $curpid);
                        $yodarow = mysqli_fetch_array($yodaresult);
                        $yodafid = $yodarow['fid'];
                        if(empty($yodafid)){
                            
                        }else{
                            commit_transaction($creatorcon);
                            continue;
                        }


                    if($paymenttype == "card"){
                        $sql = "SELECT * FROM user_customerid where userid=? " ;
                        $qresult = querye($requestorcon, $sql, $requestorid);
                        if (mysqli_num_rows($qresult) > 0) {
                                $qrow = mysqli_fetch_array($qresult);
                                $custid = $qrow['customerid'];

                                try{
                                    $charge = \Stripe\Charge::create([
                                            'amount' => $amount,
                                            'currency' => 'usd',
                                            'description' => "s-" . $creatorid,
                                            'customer' => $custid,
                                            'source' => $source,
                                        ]);

                                } catch(\Stripe\Error\Card $e){

                                  $body = $e->getJsonBody();
                                  $err  = $body['error'];

                                //  echo "<div class='paystatus'>status: unable to process payment (your payment did not go through)</div>";    
                                $sql = "UPDATE show_attendees SET userstatus=? WHERE channel=? AND userid=?";
                                querye($creatorcon,$sql, ['banned-badpayment', $channel, $requestorid]);
                                
                                $foo = getuserinfo($requestorid);
                                $fsn = $foo['screenname'];
                                $badpayments = $badpayments . $fsn . ", ";
                                  continue;
                                }

                                $amount = $amount/100;

                                $asource = \Stripe\Source::retrieve($source);

                                $country = $asource->card['country'];
                                
                                // fees are just used to get rough estimate of processing fees for internal processing
                                // vxtfees already account for all fees
                                if($country === "US"){
                                    $fees = round(.3 + $amount * .029, 2, PHP_ROUND_HALF_UP);
                                }
                                else{
                                    $fees = round(.3 + $amount * .039, 2, PHP_ROUND_HALF_UP);
                                }
                                $tid = $charge->id;
                                $status = $charge->status;


                                // charge 50% up to first 5 dollars
                                if($amount <= 5){
                                    $vxtfees = round($amount * .50, 2, PHP_ROUND_HALF_UP);
                                }
                                else{
                                    $vxtfees = 2.5 + round(($amount - 5) * .25, 2, PHP_ROUND_HALF_UP);
                                }



                                if($viewsetting == "private"){
                                    $item = "privateshow";
                                }
                                else if($viewsetting == "exclusive"){
                                    $item = "exclusiveshow";
                                }
                                else if($viewsetting == "broadcast"){
                                    $item = "broadcastshow";
                                }



                                $sql = "SELECT * FROM transactions where creatorid=? ORDER BY PID desc LIMIT 1 FOR UPDATE";
                                $mresult = querye($creatorcon, $sql, $creatorid);
                                if ($mrow = mysqli_fetch_array($mresult)) {
                                    $balance = $mrow['balance'];
                                }
                                else{
                                    $balance = 0;
                                }

                                $fees = -floatval($fees);
                                $vxtfees = -floatval($vxtfees);

                                $newbalance = floatval($balance) + floatval($amount) + floatval($vxtfees);

                                 $sql = "INSERT INTO transactions (userid, creatorid, item, sectionid, requestid, paymenttype, transactionid, status,type, amount, fees, paymentfee, balance, date)
                                 VALUES( ?, ?, ?, ?, ?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?)";
                                 querye($creatorcon, $sql,[$requestorid, $creatorid, $item, $channel, $rid, $paymenttype, $tid, $status, $amount, $vxtfees, $fees, $newbalance, $creationtime]);

                                 $fid = mysqli_insert_id($creatorcon);

                                 $sql = "UPDATE creator_show_requests SET status=?, completedon=? WHERE rid=?";
                                 $result = querye($creatorcon, $sql, ['complete', $creationtime, $rid]); 

                                 $sql = "UPDATE show_attendees SET fid=? WHERE channel=? AND userid=?";
                                 $result = querye($creatorcon, $sql, [$fid, $channel, $requestorid]); 


                                 $usercon = connectdb("normal", shr($requestorid));

                                    begin_transaction($usercon);
                                   $sql = "UPDATE user_requests SET status=? WHERE rid=? AND creatorid=?";
                                   $result = querye($usercon, $sql, ['complete', $rid, $creatorid]); 

                                    $currency = "usd";

                                    $sql = "INSERT INTO user_donations (userid, fid, item, amount, currency, creatorid, date)
                                   VALUES( ?, ?, ?, ?, ?, ?, ?)";
                                   querye($usercon, $sql, [$requestorid, $fid, $item, $amount, $currency, $creatorid, $creationtime]);

                                if(commit_transaction($creatorcon)){
                                    if(commit_transaction($usercon)){
                                    }
                                    else{
                                        echo "<div class='paystatus'>status: unable to process request transaction failure</div>";
                                    }

                                }
                                else{
                                    echo "<div class='paystatus'>status: unable to process payment</div>";
                                }
/*
                                $prow = getuserinfo($creatorid);
                                $psn = $prow['screenname'];

                                $message = "Receipt:<br><br><br>Your ticket for <b>" . $psn . "</b>'s show has been accepted and is processed: <br><br> Price: $" . $amount . " <br><br>" . $chattopic . "<br><br>order ref num: " . $fid . "-" . $creatorid . "<br><br>" . $creationtime;
                                $etype = "receipt";
                                $subject = "Receipt from Rendevus";

                                send_email($requestorid, $message, $etype, $subject);
*/
                        }
                    }
                    else{
                        commit_transaction($creatorcon);
                    }
                }
            }
            
            $sql = "UPDATE creator_shows SET showstatus=? WHERE channel=?";
            $result = querye($showcon, $sql, ['started', $room]);
        }
        else{
            $sql = "SELECT * FROM show_attendees where channel=?";
            $zresult = querye($showcon, $sql, $room);
            while ($zrow = mysqli_fetch_array($zresult)) {
                $userstatus = $zrow['userstatus'];
                $fid = $zrow['fid'];
                $curaten = $zrow['userid'];
                
                if($userstatus == "banned-badpayment"){
                    $foo = getuserinfo($curaten);
                    $rsn = $foo['screenname'];
                    
                    $badpayments = $badpayments . $rsn . ", ";
                }
            }
        }
    }
    else{
        $sql = "SELECT * FROM show_attendees where channel=? AND userid=?";
        $zresult = querye($showcon, $sql, [$room, $viewerid]);
        if ($zrow = mysqli_fetch_array($zresult)) {
            $userstatus = $zrow['userstatus'];
            $userrole = $zrow['userrole'];
            $fid = $zrow['fid'];
            $curpid = $zrow['PID'];
            $pricepaid = $zrow['price'];
            
            if(startsWith($userstatus, "banned")){
                if($userstatus == "banned-badpayment"){
                    echo "your payment did not go through, you are unable to view stream";
                    exit();
                }
                echo "you have been banned from the room";
                exit();
            }
            
            //check if show has started yet, if not wait for host
            if($showstatus == "waiting"){
                // check if host entered room with 15 minutes
                $time = strtotime($begindate);
                $lasttime = $time + (15*60);
                $curtime = time();
                if($curtime >= $lasttime){
                    echo "it is past 15 minutes since start time, show has been canceled";
                    exit();
                }
 
            }
            else if($showstatus == "started" || $showstatus == "startedwithhost"){
                            // check if payment needs to be processed
                // double check to make sure requestor has not already paid, by placing lock
                $creatorcon = connectdb("normal", shr($creatorid));
                begin_transaction($creatorcon);
                        
                $sql = "SELECT * FROM show_attendees where PID=? FOR UPDATE";
                $yodaresult = querye($creatorcon, $sql, $curpid);
                $yodarow = mysqli_fetch_array($yodaresult);
                $yodafid = $yodarow['fid'];
                $pricepaid = $yodarow['price'];
                
                if(empty($yodafid)){
                    $rid = $zrow['rid'];

                    $sql = "SELECT * FROM request_transact WHERE rid=?" ;
                    $bresult = querye($showcon, $sql, $rid);
                    if($brow = mysqli_fetch_array($bresult)){
                        $paymenttype = $brow['paymenttype'];
                        $source = $brow['source'];
                        $requestorid = $brow['requestorid'];
                        $date = $brow['date'];
                        $amount = $brow['price'];
                        
                        $amount = intval($amount *100);

                        $creationtime = date('Y-m-d H:i:s');
                        $requestorcon = connectdb("normal", shr($requestorid));

                    }


                        
                    if($paymenttype == "card"){
                        $sql = "SELECT * FROM user_customerid where userid=? " ;
                        $qresult = querye($requestorcon, $sql, $requestorid);
                        if (mysqli_num_rows($qresult) > 0) {
                                $qrow = mysqli_fetch_array($qresult);
                                $custid = $qrow['customerid'];

                                try{
                                    $charge = \Stripe\Charge::create([
                                            'amount' => $amount,
                                            'currency' => 'usd',
                                            'description' => "s-" . $creatorid,
                                            'customer' => $custid,
                                            'source' => $source,
                                        ]);

                                } catch(\Stripe\Error\Card $e){

                                  $body = $e->getJsonBody();
                                  $err  = $body['error'];

                                  echo "<div class='paystatus'>status: unable to process payment (your payment did not go through)</div>";    
                                  exit();
                                }

                                $amount = $amount/100;

                                $asource = \Stripe\Source::retrieve($source);

                                $country = $asource->card['country'];
                                if($country === "US"){
                                    $fees = round(.3 + $amount * .029, 2, PHP_ROUND_HALF_UP);
                                }
                                else{
                                    $fees = round(.3 + $amount * .039, 2, PHP_ROUND_HALF_UP);
                                }
                                $tid = $charge->id;
                                $status = $charge->status;



                                if($amount <= 5){
                                    $vxtfees = round($amount * .50, 2, PHP_ROUND_HALF_UP);
                                }
                                else{
                                    $vxtfees = 2.5 + round(($amount - 5) * .25, 2, PHP_ROUND_HALF_UP);
                                }



                                if($viewsetting == "private"){
                                    $item = "privateshow";
                                }
                                else if($viewsetting == "exclusive"){
                                    $item = "exclusiveshow";
                                }
                                else if($viewsetting == "broadcast"){
                                    $item = "broadcastshow";
                                }

                                $sql = "SELECT * FROM transactions where creatorid=? ORDER BY PID desc LIMIT 1 FOR UPDATE";
                                $mresult = querye($creatorcon, $sql, $creatorid);
                                if ($mrow = mysqli_fetch_array($mresult)) {
                                    $balance = $mrow['balance'];
                                }
                                else{
                                    $balance = 0;
                                }

                                $fees = -floatval($fees);
                                $vxtfees = -floatval($vxtfees);

                                $newbalance = floatval($balance) + floatval($amount) + floatval($vxtfees);

                                 $sql = "INSERT INTO transactions (userid, creatorid, item, sectionid, requestid, paymenttype, transactionid, status,type, amount, fees, paymentfee, balance, date)
                                 VALUES( ?, ?, ?, ?, ?, ?, ?, ?, 'debit', ?, ?, ?, ?, ?)";
                                 querye($creatorcon, $sql,[$requestorid, $creatorid, $item, $channel, $rid, $paymenttype, $tid, $status, $amount, $vxtfees, $fees, $newbalance, $creationtime]);

                                 $fid = mysqli_insert_id($creatorcon);

                                 $sql = "UPDATE creator_show_requests SET status=?, completedon=? WHERE rid=?";
                                 $result = querye($creatorcon, $sql, ['complete', $creationtime, $rid]); 

                                 $sql = "UPDATE show_attendees SET fid=? WHERE channel=? AND userid=?";
                                 $result = querye($creatorcon, $sql, [$fid, $channel, $requestorid]); 


                                 $usercon = connectdb("normal", shr($requestorid));

                                    begin_transaction($usercon);
                                   $sql = "UPDATE user_requests SET status=? WHERE rid=? AND creatorid=?";
                                   $result = querye($usercon, $sql, ['complete', $rid, $creatorid]); 

                                    $currency = "usd";

                                    $sql = "INSERT INTO user_donations (userid, fid, item, amount, currency, creatorid, date)
                                   VALUES( ?, ?, ?, ?, ?, ?, ?)";
                                   querye($usercon, $sql, [$requestorid, $fid, $item, $amount, $currency, $creatorid, $creationtime]);

                                if(commit_transaction($creatorcon)){
                                    if(commit_transaction($usercon)){
                                    }
                                    else{
                                        echo "<div class='paystatus'>status: unable to process request transaction failure</div>";
                                    }

                                }
                                else{
                                    echo "<div class='paystatus'>status: unable to process payment</div>";
                                }
/*
                                $prow = getuserinfo($creatorid);
                                $psn = $prow['screenname'];

                                $message = "Receipt:<br><br><br>Your ticket for <b>" . $psn . "</b>'s show has been accepted and is processed: <br><br> Price: $" . $amount . " <br><br>" . $chattopic . "<br><br>order ref num: " . $fid . "-" . $creatorid . "<br><br>" . $creationtime;
                                $etype = "receipt";
                                $subject = "Receipt from Rendevus";

                                send_email($requestorid, $message, $etype, $subject);
*/
                        }



                    }
                }
                else{
                    commit_transaction($creatorcon);
                }
            }
            

        }
        else{
            $ipaddress = inet_pton( $_SERVER['REMOTE_ADDR']);
            
            $sql = "SELECT * FROM show_previewers where channel=? AND userid=?";
            $result = querye($showcon, $sql, [$room, $viewerid]);
            if ($row = mysqli_fetch_array($result)) {
                header("LOCATION: purchaseshow.php?channel=" . $channel . "&creatorid=" . $creatorid);
            }
            else{
                
                if($numshowattendees === 9  || $numshowattendees === 49){
                    header("LOCATION: purchaseshow.php?channel=" . $channel . "&creatorid=" . $creatorid);
                }
                // right now we dont filter by ip address, but we can if it becomes a problem
                $sql = "INSERT INTO show_previewers (channel,userid, ipaddress)
                VALUES( ?, ?, ?)";
                querye($showcon, $sql, [$room, $viewerid, $ipaddress ]);

                 $ispreviewing = "yes";
                 $pricepaid = "previewing";
                 $userrole = "viewer";
                    
                    /*
                $sql = "SELECT * FROM show_previewers where channel=? AND ipaddress=?";
                $result = querye($showcon, $sql, [$room, $ipaddress]);
                if ($row = mysqli_fetch_array($result)) {
                    //echo "viewerid=" . $viewerid;
                    header("LOCATION: purchaseshow.php?channel=" . $channel . "&creatorid=" . $creatorid);
                }
                else{
                    $sql = "INSERT INTO show_previewers (channel,userid, ipaddress)
                   VALUES( ?, ?, ?)";
                   querye($showcon, $sql, [$room, $viewerid, $ipaddress ]);

                    $ispreviewing = "yes";
                    $pricepaid = "previewing";
                    $userrole = "viewer";
                }
                */

            }
            //header("LOCATION: purchaseshow.php?channel=" . $channel . "&creatorid=" . $creatorid);
        }
    }

}
else{
    $sql = "SELECT * FROM creator_shows where channel=?";
    $result = querye($showcon, $sql, $room);
    if ($row = mysqli_fetch_array($result)) {
        $begintime = date("l: M d, Y :: g:ia", strtotime(convZone($row['begintime'])));
        $endtime = date("g:ia", strtotime(convZone($row['endtime'])));
        //$day = $row['day'];

        //$curday = date('l: M d, Y', strtotime($day));
        
        echo "room opens on " . $begintime . " - " . $endtime ;
    }

    exit();
}


                
// expires after 24 hours

$token = array(
    "iss" => "rendevus php",
    "aud" => "rendevus node.js",
    "iat" => time(),
    "exp" => time() + (60 * 60 * 24),
    "viewerid" => $viewerid,
    "screenname" => $vsn,
    "room" => $room,
    "roomshard" => shr($room),
    "viewershard" => shr($viewerid),
    "pricepaid" => $pricepaid
);

/**
 * IMPORTANT:
 * You must specify supported algorithms for your application. See
 * https://tools.ietf.org/html/draft-ietf-jose-json-web-algorithms-40
 * for a list of spec-compliant algorithms.
 */
$jwttoken = JWT::encode($token, $GLOBALS['JWT_SECRET']);


use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VideoGrant;

use Twilio\Rest\Client;

// Substitute your Twilio AccountSid and ApiKey details

$identity = $viewerid;

$twilio = new Client($accountSid, $authToken);

try{
    // types: group-small, group, peer-to-peer
    /*
$createdroom = $twilio->video->v1->rooms
                          ->create(array(
                                       "recordParticipantsOnConnect" => false,
                                       "statusCallback" => "http://example.org",
                                       "type" => "group",
                                       "uniqueName" => $room
                                   )
                          );
*/

// peer to peer room can support up to 10 participants including host so 9 ticket holders
// for everything else use big group so up to 50
$roomtype = "";
if($numshowattendees <= 9 ){
   $createdroom = $twilio->video->v1->rooms
                          ->create(array(
                                       "enableTurn" => true,
                                       "statusCallback" => "http://example.org",
                                       "type" => "peer-to-peer",
                                       "uniqueName" => $room
                                   )
                          );
}
else{
       $createdroom = $twilio->video->v1->rooms
                          ->create(array(
                                       "statusCallback" => "http://example.org",
                                       "type" => "group",
                                       "uniqueName" => $room
                                   )
                          );
}


}
catch(Exception $e){
    
}

// people who preview can view for up to 30-60 seconds,
// this only prevents people from initiating a request with this token after the time is up
// to disconnect people who are already in a video stream, you need to manually disconnect them from node.js servers in file:index.js
if($ispreviewing === "no"){
    $toktime = $duration + 3600;
}
else{
    $toktime = $duration + 3600;
}
// Create an Access Token
$token = new AccessToken(
    $accountSid,
    $apiKeySid,
    $apiKeySecret,
    $toktime,
    $identity
);

// Grant access to Video
$grant = new VideoGrant();
$grant->setRoom($room);
$token->addGrant($grant);

// Serialize the token as a JWT
$atoken = $token->toJWT();

?>
<!DOCTYPE html>
<html>
    <head>
        <title>Watch Detail</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script src="jquery/jquery-1.11.1.min.js"></script>
        <link rel="stylesheet" href="./jquery-ui-1.11.2.custom/jquery-ui.css">
        <script src="./jquery-ui-1.11.2.custom/jquery-ui.js"></script>
        
        <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <link rel="icon" type="image/ico" href="images/favicon.ico">

        <meta http-equiv="X-UA-Compatible" content="IE=Edge"> <!-- For intranet testing only, remove in production. -->
        <meta content="text/html; charset=utf-8" http-equiv="Content-Type">

        <link rel="stylesheet" type="text/css" href="page_layout.css">
        <script type="text/javascript" src="page_layout.js"></script>
        
        <link rel="stylesheet" type="text/css" href="globalwidgets.css">
        <script type="text/javascript" src="globalwidgets.js"></script>
        
        <script src="node_modules/socket.io/socket.io.js"></script>
        <script src="https://code.responsivevoice.org/responsivevoice.js"></script> 
        <script src="//media.twiliocdn.com/sdk/js/video/releases/2.0.0-beta1/twilio-video.min.js"></script>
        
        <script src="https://unpkg.com/@tensorflow/tfjs"></script>
    <!-- Load Posenet -->
        <script src="https://unpkg.com/@tensorflow-models/posenet">
        </script>
    
       <!-- <script src="https://cdn.agora.io/sdk/web/AgoraRTCSDK-2.4-latest.js"></script> 
        <script src="https://cdn.agora.io/sdk/web/AgoraRTCSDK-2.5.0.js"></script> -->
        <style>
            
            .middle{
                width: 100%;
            }
            .maincolumn{
                padding-left: 10px;
                width: 80%;
                height: auto;

                overflow: auto;
            }
           

            #publicsection{

            }
            
            #publicsection a{
                color: blue;
                text-decoration: none;
            }
            
            #publicsection td{
                padding: 5px;
                width: auto;
                padding-left: 10px;
                padding-right: 10px;
            }
            
            #historylog{
                width: 150px;
                height: 30px;
                margin-right: 10px;
                background-color: beige;
                border-style: groove;
                border-width: 3px;
                margin-bottom: 5px;
            }
            
            .seenalready{
                background-color: lightgoldenrodyellow;
            }
            
            .dtime{
                font-size: 12px;
            }
            
            .orderid{
                font-size: 12px;
            }
            .infotd{
                max-width: 300px;
            }
            
            #local-media video {
                width: 200px;
            }
            
            .local-screencapture video{
                height: 100%;
            }
            
            #remote-media div video{
                height: 100%;
                display: block;
                margin: 0 auto;
            }
            
            #remote-media .hostscreencapture video{
                height: 100%;
                display: block;
                margin: 0 auto;
                object-fit: contain;
            }
            
            #remote-media .hostvideo video{
                height: 100%;
                display: block;
                margin: 0 auto;
                object-fit: contain;
            }

            .parent .hostvideo video{
                height: 100%;
                display: block;
                margin: 0 auto;
                object-fit: contain;
            }
            
            #remote-media {
                position: absolute;
                height: 100%;
                width: 100%;
                z-index: 9997;
                overflow: hidden;
              }
              
              #remote-media div{
                  height: 100%;
              }
              
              #remote-media-mini div video{
                height: 100%;
                display: block;
                margin: 0 auto;
                transform: rotateY(180deg);
              }
              
              #remote-media-mini {
                position: absolute;
                bottom: 10px;
                right: 10px;
                width: 300px;
                height: 225px;
                z-index: 9999;
              }

              #remote-media-mini div{
                  height: 100%;
              }
              
              #local-media {
                position: absolute;
                bottom: 10px;
                right: 10px;
                width: 200px;
                height: 150px;
                z-index: 9999;
              }

              .local-screencapture{
                position: absolute;
                bottom: 10px;
                left: 10px;
                width: 200px;
                height: 150px;
                z-index: 9999;
                overflow: hidden;
              }
              
              .host-cam{
                position: absolute;
                bottom: 10px;
                left: 10px;
                width: 300px;
                height: 225px;
                z-index: 9999;
              }
              
              .slider{
                    float: left;
                    width: 100px;
                    margin: 5px;
              }
                
              .slider .ui-slider-range { background: #ef2929; }
                
              .videosection{
                  margin-top: 10px;
                  position: relative;
                  float: left;
                  height: auto;
                  max-height: 2100px;
                  flex: 1 1 auto;
                  border: 1px black solid;
                  display: flex;
                  flex-flow: column;
              }
              
                          
              .parent {
                position: relative;
                flex: 1 1 auto;
                min-height: 200px;
                border: 1px black solid;
                background-color: black;
              }

              
              .videomenu{
                  flex: 0 1 25px;
                  border: 1px blue solid;
                  padding: 5px;
              }
                form { background: #000; padding: 2px; width: 310px; }
                form input { border: 0; padding: 10px; width: 230px; margin-right: .5%; }
                form button { width: 57px; background: rgb(130, 224, 255); border: none; padding: 10px; color: black; }
                #messages { list-style-type: none; margin: 0; padding: 0; }
                #messages li { padding: 5px 10px; font-size: 12px;}


                .chatbox{
                    margin-top: 10px;
                    position: relative;
                    float: left;
                    display: flex;
                    flex-flow: column;
                    flex:0 1 310px;
                    height: 97%;
                    margin-left: 20px;
                }

                #displaymsgs{
                    flex: 1 1 auto;
                    width: 310px;
                    overflow: auto;
                    border-left: 2px black solid;
                    border-right: 2px black solid;
                    border-top: 2px black solid;
                }
      
                .inputbox{
                    flex: 0 1 40px;
                }
                
                .userlist{
                    flex: 0 1 30px;
                    border-bottom: 1px black solid;
                    border-left: 1px black solid;
                    border-right: 1px black solid;
                    border-top: 1px black solid;
                    padding-top: 5px;
                    padding-left: 5px;
                    padding-right: 5px;
                    text-align: right;
                }
                
                #showusers{
                    list-style-type: none;
                    font-weight: bold;
                    display: none;
                    max-height: 300px;
                    overflow: visible;
                }
                
                .username{
                    font-weight: bold;
                    font-size: 12px;
                }
                
                .msgtext{
                    font-size: 12px;
                }
                
                #activate, #disconnect, #reconnect, #leaveroom, #theatersize, #fullsize, #swapscreen, #removemini, #button-share-screen{
                    border-radius: 5px;
                    background-color: white;
                    color: black;
                    margin-right: 5px;
                    border: 1px black solid;
                    float: right;
                }
                
                .hostdesc{
                    position: relative;
                    float: left;
                    clear: left;
                    width: auto;
                }
                
                .fimage{
                    width: auto;
                    height: auto;
                    float: left;
                    position: relative;
                    margin-top: 10px;
                }
                
                .hostright{
                    position: relative;
                    float: left;
                    margin-left: 10px;
                    width: auto;
                }
                .fimage img{
                    width: 50px;
                    height: 50px;
                    object-fit: cover;
                }
                
                .hostname{
                    text-decoration: none;
                    color: blue;
                    font-size: 20px;
                    font-weight: bold;
                    display: block;
                    margin-top: 5px;
                }
                

                .dropbtn {
                    background-color: white;
                    color: black;
                    padding: 1px;
                    font-size: 16px;
                    margin-left: 2px;
                    border: 1px black solid;
                    cursor: pointer;
                }

                .dropbtn:hover, .dropbtn:focus {
                    background-color: #2980B9;
                }

                .dropdown {
                    position: relative;
                    display: inline-block;
                }

                .dropdowncontent {
                    display: none;
                    position: absolute;
                    background-color: #f1f1f1;
                    width: auto;
                    overflow: visible;
                    z-index: 1;
                }

                .dropdowncontent span {
                    color: black;
                    padding: 2px 2px;
                    text-decoration: none;
                    display: block;
                }

                .dropdown span:hover {background-color: #ddd;}

                .show {display: block;}

                .outerelem{
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    z-index: 1000000;
                }
                .hostchatover{
                    margin: 0 auto;
                    width: 90%;
                    border: 3px red solid;
                    min-height: 100px;
                    font-size: 20px;
                    text-align: center;
                    vertical-align: middle;
                    line-height: 100px;     
                    background-color: white;
                    color: black;
                }
                
                .donouterelem{
                    position: absolute;
                    top: 0;
                    right: 0;
                    width: 30%;
                    z-index: 1000001;
                    display: none;
                }
                
                .donmsg{
                    margin: 0 auto;
                    width: 90%;
                    border: 5px green solid;
                    min-height: 100px;
                    font-size: 18px;
                    text-align: center;
                    vertical-align: middle;  
                    background-color: white;
                    color: black;
                    padding: 10px;
                    
                    -webkit-animation-name: example; /* Safari 4.0 - 8.0 */
                    -webkit-animation-duration: 4s; /* Safari 4.0 - 8.0 */
                    -webkit-animation-iteration-count: 3; /* Safari 4.0 - 8.0 */
                    animation-name: example;
                    animation-duration: 4s;
                    animation-iteration-count: 3;
                }
                
                /* Safari 4.0 - 8.0 */
                @-webkit-keyframes example {
                    0%   {background-color:red; }
                    25%  {background-color:yellow; }
                    50%  {background-color:blue;}
                    75%  {background-color:green; }
                    100% {background-color:orange; }
                }

                /* Standard syntax */
                @keyframes example {
                    0%   {border:8px red solid; }
                    25%  {border: 5px yellow solid; }
                    50%  {border: 8px blue solid; }
                    75%  {border: 5px green solid; }
                    100% {border: 8px orange solid; }
                }

                .closechat{
                    font-size: 20px;
                    background-color: red;
                    color: white;
                    border-radius: 10px;
                    border: 1px red solid;
                }
                
                .waitchat{
                    font-size: 20px;
                    background-color: blue;
                    color: white;
                    border-radius: 10px;
                    border: 1px blue solid;
                }
                
                .channeldonation{
                    margin-bottom: 5px;
                    position: relative;
                    float: left;
                    display: block;
                    font-size: 25px;
                    background-color: gold;
                    color: blue;
                    border-radius: 50px;
                    border: 3px gold ridge;
                    width: 150px;
                }
                
                .donname{
                    color: teal;
                    font-size: 22px;
                }
                
                .specialmsg{
                    border: 1px black dotted;
                    background-color: gainsboro;
                }
                
                .showcategory a{
                    color: blue;
                    font-size: 16px;
                    display: block;
                    text-decoration: none;
                    margin-top: 10px;
                }
                
                .volbutton{
                    height: 23px;
                    width: 23px;
                    float: left;
                    display: block;
                }
                
                #roomoptions{
                    float: right;
                    font-size: 10px;
                    width: 80px;
                    background-color: white;
                    border: 1px black solid;
                }
                
                
                .ui-selectmenu-button span.ui-selectmenu-text{
                    padding: 2px 2px 2px 2px;
                }
                
                .showinfo{
                    width: 75%;
                    float: left;
                    padding: 10px;
                }
                
                .mainone{
                    display: flex;
                    flex-flow: row;
                    flex-wrap: nowrap;
                    height: 90vh;
                }
                
                .showmeta{
                    color: gray;
                    font-weight: bold;
                }
                
                .dol1{
                    color: blue;
                }
                
                .dol2{
                    color: red;
                }
                
                #myCanvas{
                    display: none;
                }
                
                .yoplait{
                    color: red;
                    font-size: 20px;
                }
        </style>
        
                <script>

        $(document).ready(function () {

            posenet.load().then(function(net) {
                 // posenet model loaded
               });
      
            var activeRoom;
          //  var client = AgoraRTC.createClient({mode: 'live', codec: "h264"});
          //  var appid = "7911f02b15a14041bb0d56335d5ea305";

            var atoken = '<?php echo $atoken ?>';
            var channelId = '<?php echo $room ?>';
            var sockadr = '<?php echo $GLOBALS['chatmodule'] ?>';
            var ispreviewing = '<?php echo $ispreviewing ?>';
            console.log("atoken " + atoken);
            var jwttoken = '<?php echo $jwttoken ?>';
            var vsn = '<?php echo $vsn ?>';
            var viewerid = '<?php echo $viewerid ?>';
            var myrole = '<?php echo $userrole ?>';
            var badpayments = '<?php echo $badpayments ?>';
            
            var finalres = '<?php echo $finalres ?>';
            
            $(".maincolumn").append(finalres);
            const Video = Twilio.Video;
            
            // connect to chatroom socket.io
            console.log("sockadr =" + sockadr);
            var socket = io(sockadr, { query: {token: jwttoken} });
            
            // escape html
            var entityMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
          };


          if(ispreviewing === "yes"){
              setTimeout(function(){ 
                  window.location.href = "purchaseshow.php?channel=" + channelId; 
              }, 30000);          
        }
/*
        document.getElementById('button-unshare-screen').onclick = function() {
          activeRoom.localParticipant.unpublishTrack(screenTrack);
          screenTrack = null;
          document.getElementById('button-share-screen').style.display = 'inline';
          document.getElementById('button-unshare-screen').style.display = 'none';
        };
    */
   
          
         function escapeHtml (string) {
            return String(string).replace(/[&<>"'`=\/]/g, function (s) {
              return entityMap[s];
            });
          }

          function hashStr(string){
              var hash = 0;
              for (var i = 0; i < string.length; i++) {
                hash = hash + string.charAt(i).charCodeAt(0);
              }
              
              return hash;
           }
    
            $(function () {

                $('form').submit(function(){
                  socket.emit('chat message', vsn + ' : ' + $('#m').val());
                  $('#m').val('');
                  return false;
                });

                if(!badpayments){
                    
                }
                else{
                    console.log("badpayments= " + badpayments);
                    socket.emit('kick badpayments', badpayments);
                }
                
                $('.endtalk').on('click', () => {
                    var popup = "<div class='outerelem'><div class='hostchatover'>Allocated Time for Chat is over. Do you wish to end chat now(chat will exit automatically in 30 minutes)? <button class='closechat'>Close Room</button> <button class='waitchat'>Extend Chat</button></div></div>";
                    
                    $(".videosection").prepend(popup);
                });
                
                socket.on('prompt host endofchat', function(msg){
                    console.log(msg);
                    if(myrole === 'host'){
                        if($(".outerelem").length){

                        }
                        else{
                            var popup = "<div class='outerelem'><div class='hostchatover'>Allocated Time for Chat is over. Do you wish to end chat now(chat will exit automatically in 10 minutes)? <button class='closechat'>Close Room</button> <button class='waitchat'>Extend Chat</button></div></div>";
                    
                            $(".videosection").prepend(popup);
                        }

                    }

                });
                
                /*
                var msg = "One step at a time. One punch at a time. One round at a time. ";
                var username = "rocky balboa";
                var donamount = "100";


                $( ".donouterelem" ).hide();
                  */
                 /*
                  $( "#right" ).click(function() {
                    $( ".donouterelem" ).animate({ height: "toggle" }, "slow" );
                    
                    
                    curmsg = "<span class='msgtext specialmsg'>" + username + " donated $" + donamount + " - " + msg + "</span>";
                    $('#messages').append($('<li>').html(curmsg));
                    responsiveVoice.speak(msg);
                    setTimeout(function(){ $(".donouterelem").hide(); }, 20000);
                  });
*/
             //   $(".videosection").prepend(popup);
             
                var dcount = 0;
                
                socket.on('play donation message', function(data){
                    console.log(data);
                    
                    var msg = data.msg;
                    var username = data.user;
                    var donamount = data.amount;
                    
                    console.log("play don msg " + data.msg + " - " + data.user);
                    if($(".donouterelem").length){
                        
                        setTimeout(function(){                           
                            var popup = "<div class='donouterelem'><div class='donmsg'><span class='donname'>" + username + "</span> donated <span class='donname'>$" + donamount + "</span><br><br>" + msg + "</div></div>";

                            $(".videosection").prepend(popup);
                            $( ".donouterelem" ).hide();

                            $( ".donouterelem" ).animate({ height: "toggle" }, "slow" );


                            curmsg = "<span class='msgtext specialmsg'>" + username + " donated $" + donamount + " - " + msg + "</span>";
                            $('#messages').append($('<li>').html(curmsg));
                            responsiveVoice.speak(msg);
                            setTimeout(function(){ $(".donouterelem").remove(); dcount--;}, 20000);
                        }, dcount * 22000);
                        dcount++;
                    }
                    else{
                        dcount++;
                        var popup = "<div class='donouterelem'><div class='donmsg'><span class='donname'>" + username + "</span> donated <span class='donname'>$" + donamount + "</span><br><br>" + msg + "</div></div>";

                        $(".videosection").prepend(popup);
                        $( ".donouterelem" ).hide();
                        
                        $( ".donouterelem" ).animate({ height: "toggle" }, "slow" );


                        curmsg = "<span class='msgtext specialmsg'>" + username + " donated $" + donamount + " - " + msg + "</span>";
                        $('#messages').append($('<li>').html(curmsg));
                        responsiveVoice.speak(msg);
                        setTimeout(function(){ $(".donouterelem").remove(); dcount--;}, 20000);
                    }

                });
                
                socket.on('server kick badpayments', function(msg){
                    var res = msg.split(", ");
                    var i;
                    for(i=0; i < res.length; i++){
                        if(vsn === res[i]){
                            window.location.href= "kickedoutofroom.php?msg='your payment did not go through and you have been removed from room'";
                        }
                    }
                });
                
                $('#kickuser').on('click', () => {
                        
                        socket.emit('kick all users', vsn + ' kicking all users');

                });
                
                socket.on('kick all users', function(msg){
                    window.location.href = "endofchat.php?channel=" + channelId;

                });
            
            /*
               socket.on('duplicate user', function(msg){
                   if(msg == viewerid){
                       // alert("duplicate user already viewing chat, closing other chat, and try again");

                        setTimeout(function(){ 
                            window.location.href = "storyline.php";
                        }, 3000);
                    }
                });
              */  
                socket.on('chat message', function(msg){
                    var row = msg.split(" : ");
                    var name = escapeHtml(row[0]);
                    var msg = escapeHtml(row[1]);
                    
                    var hashcode = hashStr(name);
                    var now = new Date();
                    var start = new Date(now.getFullYear(), 0, 0);
                    var diff = now - start;
                    var oneDay = 1000 * 60 * 60 * 24;
                    var day = Math.floor(diff / oneDay);

                    hashcode = hashcode + day;

                    var back = ["blue","gray", "red", "green", "orange","darkgold","purple","black","gold","teal","lime","maroon", "olive", "aqua", "navy", "silver"];
                    var rand = back[hashcode % back.length];
                    
                    var yo = "<span class='username'><font color='" + rand + "'>" + name + "</font></span>: <span class='msgtext'>" + msg + "</span>";
                    $('#messages').append($('<li>').html(yo));
                    var elem = document.getElementById('displaymsgs');
                    elem.scrollTop = elem.scrollHeight;
                });
                
                socket.on('user connected', function(msg){

                    msg = "<span class='msgtext'>" + msg + "</span>";
                    $('#messages').append($('<li>').html(msg));

                });
                
                socket.on('update user list', function(msg){
                 //   console.log(msg);
                    var list = msg.split("-");
                    var numusers = list[0];
                    var userlist = list[1];
                    $('#showusers').html(userlist);
                  //  alert(numusers);
                    $(".numusers").text(numusers);
                 //   $("#showusers").append(<li id=></li>)
                });

                socket.on('closing session', function(msg){
                    popup("closing session", "session has ended, thank you");
                    $('#local-media').empty();
                });
                
                
                socket.on('delete muted user msgs', function(msg){
                    $('#messages li').each(function(i, li) {
                        var product = $(li);  
                        var curuser = product.find(".username").text();
                        console.log("curuser="+ curuser);
                        console.log("username="+ msg);
                        if(curuser === msg){
                            console.log("deleting comment");
                            product.find(".msgtext").text("<message deleted>");
                        }

                      });
                });
                
            });
      

            Video.connect(atoken, { audio: false, video: false, name: 'room-name' }).then(room => {
              console.log('Connected to Room "%s"', room.name);

              // Log any Participants already connected to the Room
                room.participants.forEach(participantConnected);
              
              
              room.on('participantConnected', participantConnected);

              room.on('participantDisconnected', participantDisconnected);
              //room.once('disconnected', error => room.participants.forEach(participantDisconnected));
              
              room.once('disconnected', function(room, error) {
                if (error) {
                  console.log('Unexpectedly disconnected:', error);
                }
                room.participants.forEach(participantDisconnected)
              });
              

              room.on('trackDisabled', function(track, participant) {
                console.log('track removed ' + track);
                $("#" + participant.sid).remove();
                if($("#" + participant.sid + "-screencapture").length !== 0){
                    $("#" + participant.sid + "-screencapture").remove();
                }
                
              });

              room.on('trackPublished', trackPublished);
              
              /*
                room.on('trackEnabled', function(track, participant) {
                    console.log('track enabled ' + track.attach());
                    
                    const div = document.createElement('div');
                    div.id = participant.sid;
                    
                });
                */        
              room.on('trackDimensionsChanged', function(track, participant) {
                console.log('track dimensions changed' + participant.sid);
                console.log(track);
                
              });
              activeRoom = room;

            });
            
            function isFirefox() {
                var mediaSourceSupport = !!navigator.mediaDevices.getSupportedConstraints()
                  .mediaSource;
                var matchData = navigator.userAgent.match(/Firefox\/(\d+)/);
                var firefoxVersion = 0;
                if (matchData && matchData[1]) {
                  firefoxVersion = parseInt(matchData[1], 10);
                }
                return mediaSourceSupport && firefoxVersion >= 52;
              }

              function isChrome() {
                return 'chrome' in window;
              }

              function canScreenShare() {
                return isChrome || isFirefox;
              }

              function getUserScreen() {
                var extensionId = "nlloieiiojghnidonmceojhgjjkcodba";
                if (!canScreenShare()) {

                  alert("can only screenshare in chrome or firefox");
                  return;
                }
                if (isChrome()) {
                  return new Promise((resolve, reject) => {
                    const request = {
                      sources: ['window', 'screen', 'tab', 'audio']
                    };
                    chrome.runtime.sendMessage(extensionId, request, response => {
                      if (response && response.type === 'success') {
                        resolve({ streamId: response.streamId });
                      } else {
                        var url = "https://chrome.google.com/webstore/detail/rendevus-video-screen-sha/nlloieiiojghnidonmceojhgjjkcodba";
                        var win = window.open(url, '_blank');
                        win.focus();
                        reject(new Error('Could not get stream'));
                      }
                    });
                  }).then(response => {
                    return navigator.mediaDevices.getUserMedia({
                        audio: {
                          mandatory: {
                                  chromeMediaSource: 'desktop',
                                  chromeMediaSourceId: response.streamId
                              }
                         },        
                      video: {
                        mandatory: {
                          chromeMediaSource: 'desktop',
                          chromeMediaSourceId: response.streamId
                        }
                      }
                    });
                  });
                } else if (isFirefox()) {
                  return navigator.mediaDevices.getUserMedia({
                    video: {
                      mediaSource: 'window'
                    }
                  });
                }
              }

              var sstoggle = 0;
              $('#button-share-screen').on('click', () => {
                  
                  if($("#local-media video").length === 0){
                      alert("must start cam before screen sharing");
                      return;
                  }
                  if(sstoggle == 0){
                      getUserScreen().then(function(stream) {
                          console.log("stream is " + stream);
                          screenTrack = stream.getVideoTracks()[0];
                          console.log(stream);
                          console.log(screenTrack);

                          activeRoom.localParticipant.publishTrack(screenTrack);
                          
                          audioTrack = stream.getAudioTracks()[0];
                          if(audioTrack != null){
                                console.log(audioTrack);

                                activeRoom.localParticipant.publishTrack(audioTrack);
                            }
                            
                         var localMediaContainer = document.createElement("div");
                         localMediaContainer.classList.add('local-screencapture');
                        var x = document.createElement("VIDEO");
                          x.srcObject = stream;
                        
                        localMediaContainer.appendChild(x);
                        x.onloadedmetadata = function(e) {
                            x.play();
                            x.muted = true;
                        };
                        $(".parent")[0].appendChild(localMediaContainer);
                          sstoggle = 1;
                      });
                  }
                  else if(sstoggle == 1){
                      activeRoom.localParticipant.unpublishTrack(screenTrack);
                      screenTrack = null;
                      $(".local-screencapture").remove();
                      sstoggle = 0;
                  }
              });
        
            
            function trackPublished(publication, participant) {
                console.log("track published");
                console.log('RemoteParticipant ${participant.sid} published Track ${publication.trackSid}');
            }
              
            var canadd = false;
            var count = 1;
            function participantConnected(participant) {
              console.log('Participant "%s" connected', participant.identity);


              console.log("creating div with count " + count);
              // handle tracks alreay published
              participant.tracks.forEach(publication => {
                  
                if (publication.isSubscribed) {
                  trackSubscribed(publication.track,participant);
                  
                }
              });
              
              // handle new tracks incoming
              participant.on('trackSubscribed', track => trackSubscribed(track, participant));
              participant.on('trackUnsubscribed', trackUnsubscribed);

             //   document.getElementById('remote-media').appendChild(div);
            //    document.getElementById('remote-media').appendChild(div);
             // if(canadd){
             //     document.getElementById('remote-media').appendChild(div);
            //   }
              
              

            }
            
            function trackSubscribed(track, participant) {

               // div.appendChild(track.attach());
             //   console.log("track subscribed name" + track.name);
                var fdata = new Object();

                fdata.type = "verifystreamid";
                fdata.streamid = participant.sid;
                fdata.channelid = channelId;

                var formdata = JSON.stringify(fdata);
               var userrole = "";
             //  console.log(formdata);
               $.ajax({
                   url: 'backend_stream.php',
                   type: 'POST',
                   data: {json: formdata},
                   success: function (data) {
                       parsedData = data; 
                       try{
                           var rdata = jQuery.parseJSON(parsedData);
                       }
                       catch(err){
                           alert(parsedData);
                       }
                       if (rdata.status === "error") {
                           console.log("streamid is not valid " + track.sid);
                           return "fail";
                       }                         
                       userrole = rdata.userrole;
                      // console.log("my role is " + myrole);
                      //  console.log("stream role is " + userrole);
                        console.log("playing stream " + track.sid);
                     //   console.log(rdata);
                        // handle case where host adds thier stream to leadviewer's view
                        // hanlde case where host adds their stream to regular view
                        // hanlde case where leadviewer adss their stream to host
                        // hanlde case where leadviewer adds their stream to regular view
                        
                        var div;
                        if($("#" + participant.sid).length == 0){
                            div = document.createElement('div');
                            div.id = participant.sid;

                        }
                        else{
                            console.log("div already exists");
                            div = $("#" + participant.sid)[0];
                        }
                        
                        if(userrole === "host"){
                            if(track.name.startsWith("regular-audio", 0) || track.name.startsWith("regular-video", 0)){
                                console.log("adding regular stream");
                                if(track.kind === "video"){
                                    $(".hostvideo video").remove();
                                }
                                else if(track.kind === "audio"){
                                    $(".hostvideo audio").remove();
                                }

                                // console.log("Subscribe remote stream successfully: " + track.sid);
                                // console.log(track.kind);
                                 div.classList.add('hostvideo');
                                 div.appendChild(track.attach());
                                 
                                 if($(".hostscreencapture").length !== 0){
                                     div.classList.add('host-cam');
                                     $(".parent").append(div);
                                }
                                else{
                                    $('#remote-media').append(div);
                                }
                                
                                if($('.hostvideo video').length !== 0){
                                    $('.hostvideo video').get(0).play();
                                }
                                
                                
                                if($('.hostvideo audio').length !== 0){
                                    $('.hostvideo audio').get(0).play();
                                }
                                
                                if(track.kind === "video"){
                                    var msg = "connecting to the stream ...";
                                    var yo = "<span class='username'> <span class='msgtext'>" + msg + "</span>";
                                    $('#messages').append($('<li>').html(yo));
                                }

                            }
                            else{
                                var scdiv;
                                if($("#" + participant.sid + "-screencapture").length == 0){
                                    scdiv = document.createElement('div');
                                    scdiv.id = participant.sid + "-screencapture";

                                }
                                else{
                                    console.log("screencapture already exists");
                                    scdiv = $("#" + participant.sid + "-screencapture")[0];
                                }

                                
                                if(track.kind === "video"){
                                    $(".hostscreencapture video").remove();
                                }
                                else if(track.kind === "audio"){
                                    $(".hostscreencapture audio").remove();
                                }
                                
                                scdiv.classList.add('hostscreencapture');
                                 scdiv.appendChild(track.attach());
                                $('#remote-media').append(scdiv);

                                $('.hostscreencapture video').get(0).play();
                                if($('.hostscreencapture audio').length !== 0){
                                    $('.hostscreencapture audio').get(0).play();
                                }
                                
                                console.log("adding host cam");
                                div.classList.add('host-cam');
                                $(div).detach();
                                $('.parent').append(div);
                                $(".host-cam video").get(0).play();
                                $(".host-cam audio").get(0).play();
                                
                            }

                                        
                         }
                         else if(myrole === "host" && userrole === "leadviewer"){
                             $(".remotevideolead").remove();
                             console.log("my role is host Subscribe remote stream successfully: " + track.sid);
                             div.classList.add('remotevideolead');
                             div.appendChild(track.attach());
                             $('#remote-media').append(div);

                            if($('.remotevideolead video').length !== 0){
                                 $('.remotevideolead video').get(0).play();
                                 asyncCall();
                                 console.log("playing wowza");
                            }
                            
                            if($('.remotevideolead audio').length !== 0){
                                $('.remotevideolead audio').get(0).play();
                            }
                            
                            if(track.kind === "video"){
                                var msg = "connecting to the stream ...";
                                var yo = "<span class='username'> <span class='msgtext'>" + msg + "</span>";
                                $('#messages').append($('<li>').html(yo));
                            }
                         }
                         else if(userrole === "leadviewer") {
                             $(".remotevideolead").remove();
                             console.log("leadviewer Subscribe remote stream successfully: " + track.sid);
                             div.classList.add('remotevideolead');
                             div.appendChild(track.attach());
                             $('#remote-media-mini').append(div);

                            if($('.remotevideolead video').length !== 0){
                                 $('.remotevideolead video').get(0).play();
                            }
                            
                            if($('.remotevideolead audio').length !== 0){
                                $('.remotevideolead audio').get(0).play();
                            }
                            
                            if(track.kind === "video"){
                                var msg = "connecting to the stream ...";
                                var yo = "<span class='username'> <span class='msgtext'>" + msg + "</span>";
                                $('#messages').append($('<li>').html(yo));

                            }
                        }

                     //   $("#remote-media").css({"height": "100%", "width": "100%", "bottom": "initial", "right" : "initial", "z-index": "9997"});
                     //   $("#remote-media div video").css({"height": "100%", "display": "block", "margin": "0 auto", "right": "initial", "width" : "100%"});
                     //   $("#remote-media div video").css({"object-fit": "contain"});

                   },
                   error: function (err, req) {
                       alert("connection to server was unsuccessful");
                   }
               });
               

               

               console.log("found a video");
              }


                function resolveAfter2Seconds() {
                  return new Promise(resolve => {
                    setTimeout(() => {
                      resolve('resolved');
                    }, 2000);
                  });
                }

                async function asyncCall() {
                  //console.log('calling');
                  
                    const scaleFactor = 0.50;
                    const flipHorizontal = false;
                    const outputStride = 16;
                    
                    const imageElement = $('.remotevideolead video').get(0);
                    
                    console.log("zimageelement is " + imageElement);
                    const net = await posenet.load();
                    const pose = await net.estimateSinglePose(imageElement, scaleFactor, flipHorizontal, outputStride);
                    console.log("pose" + pose.toString());
                  //var result = await resolveAfter2Seconds();
                  //console.log(result);
                  // expected output: 'resolved'
                }

              function trackUnsubscribed(track) {
                  console.log("trackunsubscribed " + track.name);
                  if(track.name.startsWith("regular-audio", 0) || track.name.startsWith("regular-video", 0)){
                     track.detach().forEach(element => element.remove());
                  }
                  else{
                      track.detach().forEach(element => $(element).parent().remove());
                      var curdiv = $(".host-cam")[0];
                      curdiv.classList.remove("host-cam");
                      $(curdiv).detach();
                      $("#remote-media").append(curdiv);
                      if($(".hostvideo video").length !== 0){
                          $(".hostvideo video").get(0).play();
                      }
                      
                      if($(".hostvideo audio").length !== 0){
                          $(".hostvideo audio").get(0).play();
                      }
                      
                  }
              }
              
              function participantDisconnected(participant) {
              console.log('Participant "%s" disconnected', participant.identity);
              if($("#" + participant.sid).length !== 0){
                    document.getElementById(participant.sid).remove();
                }
                
              if($("#" + participant.sid + "-screencapture").length !== 0){
                    document.getElementById(participant.sid + "-screencapture").remove();
                }
             }
             
                              
                 var createLocalVideoTrack;
                 var localtrack;
                 var seevidstats;
                 var trackcount = 0;
                 
                 $('#activate').on('click', () => {
                     
                        if($("#local-media video").length){
                            return;
                        }
                        
                        console.log("activating");
                        var fdata = new Object();
                     
                        fdata.type = "addstreamid";
                        fdata.streamid = activeRoom.localParticipant.sid;
                        fdata.channelid = channelId;

                        var formdata = JSON.stringify(fdata);
                       var that = $(this);
                 //      console.log(formdata);
                       $.ajax({
                           url: 'backend_stream.php',
                           type: 'POST',
                           data: {json: formdata},
                           success: function (data) {
                               parsedData = data; 
                               try{
                                   var rdata = jQuery.parseJSON(parsedData);
                               }
                               catch(err){
                                   alert(parsedData);
                               }
                               if (rdata.status === "error") {
                                   alert(rdata.errmsg);
                                   return "fail";
                               }                            
                             
                       
                           },
                           error: function (err, req) {
                               alert("connection to server was unsuccessful");
                           }
                       });



                        // Request audio and video tracks
                        Video.createLocalTracks({
                            audio: { name: 'regular-audio-' + trackcount },
                            video: { name: 'regular-video-' + trackcount }
                          }).then(function(localTracks) {
                          const localMediaContainer = document.getElementById('local-media');
                          localTracks.forEach(function(track) {
                              console.log("local track is " + track.name);
                                localMediaContainer.appendChild(track.attach());
                                activeRoom.localParticipant.publishTrack(track);
                          });
                        });
                       trackcount++;
                        
                        if(myrole === 'host'){
                            // take snapshot preview of room after 60 seconds delay
                            setTimeout(function(){ 
                                snapshot();
                            }, 40000);
                        }
                    });
                 
         

            
                function snapshot() {
                    console.log("taking a snapshot");
                  var canvas = document.getElementById("myCanvas");
                  var ctx = canvas.getContext('2d');
                   // Draws current image from the video element into the canvas
                   var avideo = $("#local-media").find("video");
                   var yovideo = avideo[0];
                  ctx.drawImage(yovideo, 0,0, canvas.width, canvas.height);
                  var img    = canvas.toDataURL("image/png");
                  //console.log(img);
                  //$(".maincolumn").append('<img src="'+img+'"/>');
                  
                  var blobBin = atob(img.split(',')[1]);
                    var array = [];
                    for(var i = 0; i < blobBin.length; i++) {
                        array.push(blobBin.charCodeAt(i));
                    }
                    var file=new Blob([new Uint8Array(array)], {type: 'image/png'});

                  var formData = new FormData();
                    formData.append('photo', file);
                    formData.append('type', 'roomsnapshot');
                    formData.append('channel', channelId);
                    var parsedData = "nochange";

                    $.ajax({
                        url: 'uploadImages.php',
                        type: 'POST',
                        data: formData,
                        async: true,
                        cache: false,
                        processData: false,
                        contentType: false,
                        beforeSend:function(){

                         },
                        success: function (data) {
                            parsedData = data;
                            //alert(parsedData);
                            var rdata = jQuery.parseJSON(parsedData);

                            if (rdata.status === "error") {
                                return "fail";
                            } 
                            console.log("fil has been uploaded");
                            console.log(rdata.msg);
                        },
                        error: function (err, req) {
                            alert("connection to server was unsuccessful");
                        }
                    });

                }
      
            
              $('#disconnect').on('click', () => {
                    console.log("clicked pause");
                    //activeRoom.disconnect();
                    //

                    // localtrack.detach();
                   //  localtrack.stop();
                     
                    activeRoom.localParticipant.tracks.forEach(publication => {
                      //publication.track.unpublish();
                      console.log(publication);
                      publication.track.disable();
                      
                      //publication.unpublish();
                      var attachedElements = publication.track.detach();
                     // publication.track.stop();
                      console.log("unsubscribed from: " + publication.track);
                      attachedElements.forEach(element => element.remove());
                   });

            });
            
/*
            
              $('#reconnect').on('click', () => {
                    console.log("clicked reconnect");
        
                    activeRoom.localParticipant.tracks.forEach(publication => {
                      //publication.track.unpublish();
                      console.log(publication);
                      publication.track.enable();
                   });      
                   
                    const localMediaContainer = document.getElementById('local-media');
                    $("#local-media").empty(); 
                     activeRoom.localParticipant.videoTracks.forEach(publication => {

                       localMediaContainer.appendChild(publication.track.attach());

                    });
                       
                   $('#local-media video').show();
                   $('#local-media video').trigger('play');
                   
                });
  */          
                $('#leaveroom').on('click', () => {
                    console.log("deactivating camera");
                   
                   activeRoom.localParticipant.tracks.forEach(publication => {
                      //publication.track.unpublish();
                      console.log(publication);
                      publication.track.disable();
                      
                      //publication.unpublish();
                      var attachedElements = publication.track.detach();
                      
                      attachedElements.forEach(element => element.remove());
                    //  publication.track.stop();
                   });
                   
                   activeRoom.localParticipant.tracks.forEach(publication => {

                      publication.track.stop();
                   });
                   
                  // activeRoom.disconnect();

                      $(".local-screencapture").remove();
                      sstoggle = 0;
                });
                
                $(document).keyup(function(e) {
                 
                  if (e.key === "Escape") {
                      console.log("hit esc key");
                        $(".left").show();
                        $(".top").show();
                        $(".footer").show();
                        $(".maincolumn").css("width","80%");
                        $(".videosection").css("height","80%");
                        $(".chatbox").css("height","80%");
                        flip = 0;                     
                  }
                  // esc
                });
                
                var flip = 0;
                $('#theatersize').on('click', () => {
                    console.log("theater size");
                    
                    if( flip === 0){
                        $(".left").hide();
                        $(".top").hide();
                        $(".footer").hide();
                        $(".maincolumn").css("width","95%");

                        $(".mainone").css("height", "100vh");
                        flip = 1;
                    }
                    else{
                        $(".left").show();
                        $(".top").show();
                        $(".footer").show();
                        $(".maincolumn").css("width","80%");
                        $(".mainone").css("height", "90vh");
                        flip = 0;
                    }
                });
                
                $('#fullsize').on('click', () => {
                    console.log("fullsize room");
                    var elem = $('#remote-media video')[0];
                    if (elem.requestFullscreen) {
                      elem.requestFullscreen();
                    } else if (elem.mozRequestFullScreen) {
                      elem.mozRequestFullScreen();
                    } else if (elem.webkitRequestFullscreen) {
                      elem.webkitRequestFullscreen();
                    } else if (elem.msRequestFullscreen) { 
                      elem.msRequestFullscreen();
                    }
                });
                
                var toggle = 0;
                $('.userlisttitle').on('click', () => {
                    if(toggle === 0){
                        $('#showusers').show();
                        toggle = 1;
                    }
                    else{
                        $('#showusers').hide();
                        toggle = 0;
                    }
                });
                
                var scrtoggle = 0;
                $('#swapscreen').on('click', () => {
                    if(scrtoggle === 0){
                    //    $("#local-media").insertBefore($("#remote-media"));
                        $("#local-media").css({"height": "100%", "width": "100%", "bottom": "initial", "right" : "initial", "z-index": "9997"});
                        $("#local-media video").css({"height": "100%", "display": "block", "margin": "0 auto", "right": "initial", "width" : "100%"});
                        $("#local-media video").css({"object-fit": "contain"});
                        $("#remote-media").css({"height": "150px", "width": "200px", "bottom": "10px", "right" : "10px", "z-index": "9999"});
                     //   $("#remote-media div video").css({"height": "initial", "display": "initial", "margin": "initial", "right" : "10px", "width": "200px"});
                        scrtoggle = 1;
                    }
                    else{
                      //  $("#remote-media").insertBefore($("#local-media"));
                        $("#remote-media").css({"height": "100%", "width": "100%", "bottom": "initial", "right" : "initial", "z-index": "9997"});
                        $("#remote-media div video").css({"height": "100%", "display": "block", "margin": "0 auto", "right": "initial", "width" : "100%"});
                        $("#remote-media div video").css({"object-fit": "contain"});
                        $("#local-media").css({"height": "150px", "width": "200px", "bottom": "10px", "right" : "10px", "z-index": "9999"});
                        $("#local-media video").css({"height": "100%", "display": "initial", "margin": "initial", "right" : "initial", "width": "100%"});
                        scrtoggle = 0;
                    }
                });
                
                    
                  $( function() {
                    var slider = $( ".slider" );
                    slider.slider({range:"min", 
                        value: 50,
                        change: function(event, ui) { 
                        //  $("#remote-media audio").prop("volume", ui.value/100);
                        console.log("changing vol to " + ui.value);
                        if(ui.value <= 10){
                            ui.value = 0;
                        }
                          $("#remote-media audio").prop("volume", ui.value/100);
                          $("#remote-media video").prop("volume", ui.value/100);
                          $("#remote-media-mini audio").prop("volume", ui.value/100);
                          $("#remote-media-mini video").prop("volume", ui.value/100);
                        } 
                    });
                  });
                               

            
            $(".maincolumn").on("click", ".closechat", function () {
                //window.location.href = "endofchat.php?channel=" + channelId;
                socket.emit('kick all users', vsn + ' kicking all users');
            });
            
            $(".maincolumn").on("click", ".waitchat", function () {
                $(".outerelem").hide();
            });
            
            $(".maincolumn").on("click", "#removemini", function () {
                $("#remote-media-mini").toggle("show");
            });
            
            /* When the user clicks on the button, 
            toggle between hiding and showing the dropdown content */

            $(".maincolumn").on("click", ".dropbtn", function () {
                $(this).siblings(".myDropdown").toggle("show");
            });

            $(".maincolumn").on("click", ".muteuser", function () {
                var userid = $(this).attr('id');
                var username = $(this).closest("li").find(".userinlist").text();
                console.log("muting " + userid);
                 var fdata = new Object();

                 if($(this).text() === "Mute"){
                     fdata.type = "muteuser";
                 }
                 else{
                     console.log($(this).text());
                     fdata.type = "unmuteuser";
                }
                 fdata.userid = userid;
                 fdata.channelid = channelId;

                 var formdata = JSON.stringify(fdata);
                console.log(formdata);
                var that = $(this);
                $.ajax({
                    url: 'backend_stream.php',
                    type: 'POST',
                    data: {json: formdata},
                    success: function (data) {
                        parsedData = data; 
                        try{
                            var rdata = jQuery.parseJSON(parsedData);
                        }
                        catch(err){
                            alert(parsedData);
                        }
                        if (rdata.status === "error") {
                            alert(rdata.errmsg);
                            return "fail";
                        }
                        if(that.text() === "Mute"){
                            that.text("Unmute");
                        }
                        else{
                            that.text("Mute");
                        }
                    },
                    error: function (err, req) {
                        alert("connection to server was unsuccessful");
                    }
                });
                

                  socket.emit('muting user', username);

            });
            
            $(".maincolumn").on("click", ".seeprofile", function () {
                var userid = $(this).attr('id');
                var username = $(this).closest("li").find(".userinlist").text();
                console.log("see profile " + userid);
                var win = window.open('profile.php?username=' + username, '_blank');
                if (win) {
                    //Browser has allowed it to be opened
                    win.focus();
                } else {
                    //Browser has blocked it
                    alert('Please allow popups for this website');
                }
            });
            
            $(".maincolumn").on("click", ".channeldonation", function () {
                window.open("donatetochannel.php?channel=" + channelId, '_blank');
            });

            $('#roomoptions').change(function() {
                if ($(this).val() === 'close stream') {
                    // Do something for option "b"
                    confirmpopup("Ending Stream", "Are you sure to want to close stream? Stream cannot be reopened later");
                }
            });

            // Close the dropdown if the user clicks outside of it
            window.onclick = function(event) {
                
              if (!event.target.matches('.dropbtn')) {

                var dropdowns = document.getElementsByClassName("dropdowncontent");
                var i;
                for (i = 0; i < dropdowns.length; i++) {
                  var openDropdown = dropdowns[i];
                  $(openDropdown).hide();
                }
              }
            }
            
                function confirmpopup(title, body){

                    $(".maincolumn").append('<div id="dialog-popup" title="' + title + '">\
                        <p>\
                            ' + body + '\
                        </p>\
                    </div>');

                $( "#dialog-popup" ).dialog({
                        modal: true,
                        autoOpen: false,
                        buttons: {
                          Ok: function() {
                              socket.emit('kick all users', vsn + ' kicking all users');
                                $( this ).dialog( "close" );
                                $("#dialog-popup").remove();
                          },
                          Cancel: function(){
                                $( this ).dialog( "close" );
                                $("#dialog-popup").remove();
                            }
                        },
                          close: function() {
                             $(this).dialog( "close" );
                             $("#dialog-popup").remove();
                         },
                        position: {
                        my: "right top",
                        at: "right top"
                        }
                });

                $("#dialog-popup").dialog("open");
                return;
                }
                
                $(".maincolumn").on("click", ".volbutton", function () {
                    if($(".volbutton").attr("src") === "images/mute.png"){
                        $(".volbutton").attr("src", "images/unmute.png");
                        ismute = true;
                        $(".hostvideo audio").prop("muted", ismute);
                        $(".hostvideo video").prop("muted", ismute);
                        $(".hostscreencapture audio").prop("muted", ismute);
                        $(".hostscreencapture video").prop("muted", ismute);
                    }
                    else{
                        $(".volbutton").attr("src", "images/mute.png");
                        ismute = false;
                        $(".hostvideo audio").prop("muted", ismute);
                        $(".hostvideo video").prop("muted", ismute);
                        $(".hostscreencapture audio").prop("muted", ismute);
                        $(".hostscreencapture video").prop("muted", ismute);
                    }
                });
                
                
                setInterval(function(){ 
                    setUpHeartbeat(); 
                }, 3000);
                
                
                function setUpHeartbeat(){
                      
                    $( ".videomenu" ).each(function() {
                        var boo = $(this).find(".yoplait");
                        boo.remove();
                        
                        var hbeat = Math.floor((Math.random() * 50) + 50);
                        
                        $( this ).append( "<div class='yoplait'>" + hbeat + " bpm</div>" );
                      });
                      
                }
                
                /*
                var menutimeout;
                $(".maincolumn").on("mousemove", ".parent", function () {
                    clearTimeout(menutimeout);
                    $("#videostats").show();
                    
                    menutimeout = setTimeout(function() {
                        $("#videostats").hide();
                    }, 4000);
    
                });
                
                $(".maincolumn").on("mouseleave", ".parent", function () {
                    $("#videostats").hide();
                });
*/
        });
        </script>
    </head>
    <body>
        <div class="maincolumn">
                
                <?php
                    echo "<div class='mainone'><div class='videosection'><div class='parent'><div id='remote-media'> </div>";
                    
                    if($userrole == "host" || $userrole == "leadviewer"){
                        echo "<div id='local-media'></div></div>";
                    }
                    else{
                        echo "<div id='remote-media-mini'></div></div>";
                    }

                    echo "<div class='videomenu'><img class='volbutton' src='images/mute.png'><div class='slider'></div>";
                    
                    echo "<button id='fullsize'>FullScreen</button><button id='theatersize'>Theater</button>";
                    
                    if($userrole == "viewer"){
                        echo "<button id='removemini'>Miniscreen</button>";
                    }
                    

                    
                    if($userrole == "host" || $userrole == "leadviewer"){
                        echo "<button id='swapscreen'>Toggle View</button>";
                        
                        if($userrole == "host"){
                            echo "<button id='button-share-screen'>Screen Share</button>";
                        }
                    
                        echo "<button id='leaveroom'>Disable Cam</button><button id='activate'>Activate Cam</button>";
                    }
                    


                    $row = getuserinfo($creatorid);
                    
                    $output = "<div class='hostdesc'><div class='fimage'><a href='" . $row['profilepage'] . "'><img src='" . outclean($row['avatarimage']) . "'></a></div><div class='hostright'><a class='hostname' href='" . $row['profilepage'] . "'>" .$row['screenname'] . "</a><span class='hoststatus'>" . $chattopic . "</span><br><span class='showcategory'><a href='relatedshows.php?category=" . $category . "'>" . $category . "</a></span></div>";
                    
                    $output = $output . "</div>";
                    echo $output;
                    echo "</div>";
                    
                    
                    echo "</div>";
                    echo '     <div class="chatbox"><div>';
                    if($userrole == "host"){
                        echo '<select name="roomoptions" id="roomoptions"><option selected="selected">options</option>
                        <option value="close stream">Close Stream</option>
                      </select>';
                    }
                    echo '
</div>
         <div id="displaymsgs">
        <ul id="messages"></ul>
         </div>
         <div class="inputbox">
            <form action="">
              <input id="m" autocomplete="off" /><button>Send</button>
            </form>
        </div>
        <div class="userlist">
        <span class="userlisttitle">People(<span class="numusers">0</span>)</span>
        <ul id="showusers">
        
        </ul>';
                    
              if(!empty($badpayments)){
                    echo "<br><br>unable to process payments from " . $badpayments . '
                    </div>
                 </div>';
                    }
                    else{
                        echo "</div></div>";
                    }
                    
                    echo "</div>";
                    if($viewsetting === "broadcast"){
                        echo "<div class='showinfo'>";
                        $sql = "SELECT * FROM show_information where channel=?";
                        $result = querye($showcon, $sql, $room);
                        if ($row = mysqli_fetch_array($result)) {
                            $content = $row['information'];
                            $filteredcontent = purify($purifier, $content);
                            echo "<div class='showmeta'>Funds raised: <span class='dol1'>$". $totalamountsold . "</span> , target goal: <span class='dol2'>$" . $targetprice . "</span></div>";
                            echo $filteredcontent;
                        }
                        echo "</div>";
                    }
                    echo "</div>";
                ?>

            <canvas  id="myCanvas" width="400" height="350"></canvas> 
    </body>
</html>
