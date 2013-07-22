<?php

// Written by Kevin Prigel
// (c) KDP Innovations, LLC
// Licensed under the Apache License.  See LICENSE for details
// 
// 
// 
// You must have ImageMagick and Imagick installed for this to work properly
//  You need to have a domain name in Rackspace Cloud DNS that is inserted in
//  the ParentDomain field to create CNAMES pointing to the public CDN URL
//  of containers.  You need a Rackspace Cloud account (though this can likely
//  be modified to work with other OpenStack deployments.  Also, sign up for
//  an account at Iron.io for the message queue service (10M messages per month
//  for free!).   Libraries required include php-opencloud, Ironcore, and 
//  IronMQ.

require_once('php-opencloud.php');
require_once "IronCore.class.php";
require_once "IronMQ.class.php";

$ironmq = new IronMQ(array(
    "token" => 'YOUR TOKEN HERE',
    "project_id" => 'YOUR MESSAGE ID HERE',
    'host' => 'mq-rackspace-dfw.iron.io'
));

// post test message -  comment this out once the PutFile method is implemented
// see PutFile.php for definitions
$postmessage=json_encode(array("ImageName"=>"IMG_1100.JPG",
    "CurrentContainer"=>"toprocess",
    "NewContainerName"=>"456",
    "ParentDomain"=>"YOURDOMAIN.COM",
    "SuffixFormat"=>array('','_web','_mob','_tweb','_tmob'),
    "WidthArray"=>array(2000,1200,960,500,600),
    "HeightArray"=>array(1500,900,640,500,600),
    "QualityArray"=>array(100,90,80,80,60),
    "MaintainAspect"=>array(TRUE,TRUE,TRUE,FALSE,FALSE),
    "ForceWidth"=>array(0,0,0,1,1),
    "KeepOriginal"=>0,
    "idFile"=>"578946"));

$ironmq->postMessage("iprocess",$postmessage);


//get message

$process_info=$ironmq->getMessage("iprocess",60);
while (empty($process_info)){
    sleep(10);
    $process_info=$ironmq->getMessage("iprocess",60);
}

$process_me=json_decode($process_info->body);
        
$toprocess=$process_me->CurrentContainer;
$imagename=$process_me->ImageName;
$container_name=$process_me->NewContainerName;
$suffixformat=$process_me->SuffixFormat;
$widtharray=$process_me->WidthArray;
$heightarray=$process_me->HeightArray;
$qualityarray=$process_me->QualityArray;
$aspectarray=$process_me->MaintainAspect;
$parent_domain=$process_me->ParentDomain;
$forcewidth=$process_me->ForceWidth;
$keeporiginal=$process_me->KeepOriginal;
$idFile=$process_me->idFile;


define('AUTHURL', RACKSPACE_US);
define('USERNAME', 'RACKSPACE USERNAME');
define('APIKEY', 'RACKSPACE APIKEY');

// establish our credentials
$connection = new \OpenCloud\Rackspace(AUTHURL,
array( 'username' => USERNAME,
'apiKey' => APIKEY));
$conn=$connection->ObjectStore('cloudFiles','DFW');

// if $containername does not exist, create it, publish it to the CDN, and
//  create a CNAME under ParentDomain that points to the container
try { $container = $conn->Container($container_name); }
catch (OpenCloud\Common\Exceptions\ContainerNotFoundError $e) {
ContAndCNAME($container_name,$parent_domain);}

/* START THE IMAGES SECTION */

$tempimagename=md5(microtime());

$container_to_process=$conn->Container($toprocess);
$file_to_process=$container_to_process->DataObject($imagename);
$file_to_process->SaveToFileName($imagename);
$basefilename=PATHINFO($imagename,PATHINFO_FILENAME);
$tempimagename=$tempimagename.".".pathinfo($imagename,PATHINFO_EXTENSION);
rename($imagename,$tempimagename);
$originalimagename=$imagename;
$imagename=$tempimagename;

$count=count($suffixformat);

$container_new=$conn->Container($container_name);

$im=new Imagick();
$im->readimage($imagename);


//  If the image is rotated, correct it

$orientation = $im->getImageOrientation();

    switch($orientation) {
        case imagick::ORIENTATION_BOTTOMRIGHT:
            $im->rotateimage("#000", 180); // rotate 180 degrees
        break;

        case imagick::ORIENTATION_RIGHTTOP:
            $im->rotateimage("#000", 90); // rotate 90 degrees CW
        break;

        case imagick::ORIENTATION_LEFTBOTTOM:
            $im->rotateimage("#000", -90); // rotate 90 degrees CCW
        break;
    }


// Get EXIF data from the original image, save it to post to the message 
//    queue when processing finishes
    
$exif=$im->getImageProperties('exif:*') ;


// strip exif data and rewrite source image to largest dimensions for faster
//  processing of large images

$maxwidth=max($widtharray);
$maxheight=max($heightarray); 
$im->resizeImage($maxwidth,$maxheight,imagick::FILTER_LANCZOS,1,TRUE);
$d = $im->getImageGeometry();
$w = $d['width'];
$h = $d['height'];

// Now that it's auto-rotated, make sure the EXIF data is correct in case the 
// EXIF gets saved with the image!
$im->stripImage();
$im->setImageOrientation(imagick::ORIENTATION_TOPLEFT);
$im->setImageFormat('jpeg');
$im->setImageCompression(Imagick::COMPRESSION_JPEG);   
$im->setCompressionQuality(100);
$im->writeimage($imagename);

 

    
    
for ($i=0;$i<$count;$i++){
    $cycletime=microtime(true);
    
    $newfilename[$i]=$basefilename.$suffixformat[$i].".jpg";
    $newfiletemp[$i]=MD5(microtime()).".jpg";
    //echo $newfilename[$i]."\n";
    //echo $newfiletemp[$i]."\n";
        
    $im->readImage($imagename);

    $readtime=microtime(true)-$cycletime;
    $cycletime=microtime(true);
    //echo "Readtime ".$readtime."\n";
            
    if ($aspectarray[$i]==TRUE){
        // This resizes the image, maintaining the current aspect ratio.  
        // As written, it resizes the largest dimension of the image to the 
        // value specified in width.
        if ($w>$h){
            $im->resizeImage($widtharray[$i],$heightarray[$i],imagick::FILTER_LANCZOS,1,TRUE);}
        else {
            $im->resizeImage($heightarray[$i],$widtharray[$i],imagick::FILTER_LANCZOS,1,TRUE);}
        }
        
    else {  
     // processing if MaintainAspect=FALSE (for making square or other size
     // images
      if ($forcewidth[$i]=0){
          //  This creates a crop thumbnail that is exactly the width and height
          $im->cropthumbnailimage($widtharray[$i],$heightarray[$i]);}
      else {
          if ($w>$h){
         // if forcewidth=1 then the width is treated as fixed, generating an 
         // image that if is a landscape style image, will generally make a 
         // landscape style image, and if it is a portrait orientation image
         // will generate an square image (Usage would generally be for
         // WIDTH=HEIGHT, MaintainAspect=FALSE, ForceWidth=1).  Essentially, 
         // this creates thumbnails like those in the Facebook iPhone app which
         // are square for portrait orientation images, and rectangular for
         // landscape orientation images.
              $im->resizeImage($widtharray[$i],$heightarray[$i],imagick::FILTER_LANCZOS,1,TRUE);}
          else{
              $im->cropthumbnailimage($widtharray[$i],$heightarray[$i]);
          }
          }
      }
    
    $im->setImageFormat('jpeg');
    $im->setImageCompression(Imagick::COMPRESSION_JPEG);   
    $im->setCompressionQuality($qualityarray[$i]);
    $im->writeimage($newfiletemp[$i]);

    $im->Clear();
    
}
    
for ($j=0;$j<$count;$j++){
    // write new files to Cloud Files in NewContainerName
    $write_file=$container_new->DataObject();
    $write_file->Create(array('name' => $newfilename[$j]),$newfiletemp[$j]);
    
    // delete local copy of image
    unlink($newfiletemp[$j]);
}


// delete original image
unlink($imagename);

$im->destroy();

// add message to queue that file is processed with EXIF data
$postmessage=json_encode(array("idFile"=>$idFile,"FilesGenerated"=>$count,"exif"=>$exif));
$ironmq->postMessage("filesprocessed",$postmessage);

// delete message from queue  
$ironmq->deleteMessage("iprocess",$process_info->id);

// now delete original image if KeepOriginal=0
if ($keeporiginal=0){
$container_to_delete_from=$conn->Container($toprocess);
$file_to_delete=$container_to_delete_from->DataObject($originalimagename);
$file_to_delete->Delete();}





flush();

exit();



Function ContAndCNAME($ContName,$BaseDomainName){

// establish our credentials
$connection = new \OpenCloud\Rackspace(AUTHURL,
array( 'username' => USERNAME,
'apiKey' => APIKEY));

// connect to Cloud Files
$objstore = $connection->ObjectStore('cloudFiles', 'DFW');

// create container
$container = $objstore->Container();
$container->Create(array('name'=>$ContName));

// publish container to cdn, get url
$cdnversion = $container->PublishToCDN();
$CNAMEResolves2 =$container->PublicURL();
$CNAMEResolves=substr($CNAMEResolves2,7);

// connect to dns
$dns = $connection->DNS();

// create new cname
$NewCNAME = $ContName . "." . $BaseDomainName;

$dlist=$dns->DomainList(array('name'=>$BaseDomainName));
$domain=$dlist->Next();

$record = $domain->Record();
$record->Create(array(
'type' => 'CNAME',
    'name' => $NewCNAME,
    'ttl' => 600,
    'data' => $CNAMEResolves));
}



?>