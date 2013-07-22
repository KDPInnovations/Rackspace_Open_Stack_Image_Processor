<?php
//
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
//  
//  
// $temporarycontainer = temporary container for image uploads, this is the 
//      container where the images will be stored until FileProcessor2.php
//      process the image.  If the container does not exist, it will be created
//      and WILL NOT be publicly available
// $PathToImage = Path on web server where image to copy is located
// $FileToCopy = image to copy - also used as the base name for processed images
//          for example, an image named 123456789.jpg when processed will 
//          generate 123456789.jpg (if one on the SuffixArray items is ""), 
//          123456789_web.jpg, 123456789_mob.jpg etc.      
// $newcontainer = container name for processed files.  If the container does
//          not exist, FileProcessor2.php will create the container, publish
//          it to the CDN (make it publicly accessible), and create a CNAME 
//          entry under ParentDomain (see below).
// 
// =====================
// Message Contents
// =====================
// "ImageName" = $FileToCopy
// "CurrentContainer" = $temporarycontainer, where $FileToCopy was written
// "NewContainerName" = $newcontainer, container where the processed images
//          will be stored 
// "ParentDomain" = this is set to a DNS entry in Rackspace Cloud DNS which will 
//         have a CNAME which points to the $newcontainer.  For example, for 
//         $newcontainer 714, and ParentDomain YOURDOMAIN.COM, a CNAME of 
//         714.YOURDOMAIN.COM will point to the CDN url of $newcontainer
// "SuffixFormat" = array of suffixes to attach to processed images 
//              Example: array('','_web','_mob','_tweb','_tmob')
// "WidthArray" =  array of image widths 
//              Example: array(2000,1200,960,600,600)
// "HeightArray"= array of image heights
//              Example: array(1500,900,640,600,600)
// "QualityArray"= array of JPG quality
//              Example: array(100,90,90,90,75)
// "MaintainAspect"= array that is TRUE if resize to largest side, FALSE for 
//          resize and crop to exact
//              Example: array(TRUE,TRUE,TRUE,FALSE,FALSE)
//  "ForceWidth" = array that if set to 1 for size with MaintainAspect=FALSE 
//          forces image to size to width (for thumbnailing for timeline like 
//          layouts)
//              Example: "ForceWidth"=>array(0,0,0,1,1) 
//  "KeepOriginal" = Set to 1 to keep the original file in the temporary container
//          after processing, otherwise set to 0 (should usually be set to 0 
//          except for testing purposes)
//  $idFile = your database key for File record
// 

// Image processing notes (FileProcessor2.php):
// 
// For an image to process with MaintainAspect=TRUE, the largest side of the 
// image is always treated as the width, so for a landscape picture, the picture
// will be resized with a height equal to the value set in WidthArray.
// 
// ForceWidth changes the behavior of MaintainAspect = FALSE to treat the width
//  as fixed, and height as variable if set to 1, creating a thumb that always 
//  has width as specified.  If the image is in portrait mode, you will end up 
//  with a thumb that is WIDTH and HEIGHT as specified.  If the image is in 
//  landscape, the thumb will be WIDTH, with height scaled as appropriate.  
//  FALSE and 0 creates a thumb with WIDTH and HEIGHT as specified cropping 
//  as appropriate.  

$temporarycontainer= "toprocess";
        
require_once('php-opencloud.php');
require_once "IronCore.class.php";
require_once "IronMQ.class.php";

define('AUTHURL', RACKSPACE_US);
define('USERNAME', 'RACKSPACE USERNAME');
define('APIKEY', 'RACKSPACE API KEY');

// establish our credentials
$connection = new \OpenCloud\Rackspace(AUTHURL,
array( 'username' => USERNAME,
'apiKey' => APIKEY));
$conn=$connection->ObjectStore('cloudFiles','DFW');

// make sure $currentcontainer exists, if not create it
try { $container = $conn->Container($temporarycontainer); }
catch (OpenCloud\Common\Exceptions\ContainerNotFoundError $e) {
$container = $conn->Container();
$container->Create(array('name'=>$temporarycontainer));}

// write $FileToCopy to Cloud Files in container $temporarycontainer
$container_new=$conn->Container($temporarycontainer);
$write_file=$container_new->DataObject();
$write_file->Create(array('name' => $FileToCopy),$PathToImage.$FileToCopy);

$ironmq = new IronMQ(array(
    "token" => 'iron.io token',
    "project_id" => 'iron.io project_id',
    'host' => 'mq-rackspace-dfw.iron.io'
));


// Replace these with variables as appropriate, see top for definitions

$postmessage=json_encode(array("ImageName"=>$FileToCopy,
    "CurrentContainer"=>$temporarycontainer,
    "NewContainerName"=>$newcontainer,
    "ParentDomain"=>"YOURDOMAIN.COM",
    "SuffixFormat"=>array('','_web','_mob','_tweb','_tmob'),
    "WidthArray"=>array(2000,1200,960,600,600),
    "HeightArray"=>array(1500,900,640,600,600),
    "QualityArray"=>array(100,90,90,75,60),
    "MaintainAspect"=>array(TRUE,TRUE,TRUE,FALSE,FALSE),
    "ForceWidth"=>array(0,0,0,1,1),
    "KeepOriginal"=>0,
    "idFile"=>$idFile));

$ironmq->postMessage("iprocess",$postmessage);

flush();

exit();

?>
