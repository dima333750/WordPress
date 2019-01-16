<?php
//Gzip compress static html
$whitelistPatterns = array(
);
$forceCORS = false;
$anonymize = true;
$startURL = "";

ob_start("ob_gzhandler");

if (version_compare(PHP_VERSION, "5.4.7", "<")) {
  die("Gzip requires PHP version 5.4.7 or later.");
}

$requiredExtensions = ['curl', 'mbstring'];
foreach($requiredExtensions as $requiredExtension) {
  if (!extension_loaded($requiredExtension)) {
    die("Gzip requires PHP's \"" . $requiredExtension . "\" extension. Please install/enable it on your server and try again.");
  }
}


function getHostnamePattern($hostname) {
  $escapedHostname = str_replace(".", "\.", $hostname);
  return "@^https?://([a-z0-9-]+\.)*" . $escapedHostname . "@i";
}


function removeKeys(&$assoc, $keys2remove) {
  $keys = array_keys($assoc);
  $map = array();
  $removedKeys = array();
  foreach ($keys as $key) {
    $map[strtolower($key)] = $key;
  }
  foreach ($keys2remove as $key) {
    $key = strtolower($key);
    if (isset($map[$key])) {
      unset($assoc[$map[$key]]);
      $removedKeys[] = $map[$key];
    }
  }
  return $removedKeys;
}

if (!function_exists("getallheaders")) {

  function getallheaders() {
    $result = array();
    foreach($_SERVER as $key => $value) {
      if (substr($key, 0, 5) == "HTTP_") {
        $key = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
        $result[$key] = $value;
      }
    }
    return $result;
  }
}

$usingDefaultPort =  (!isset($_SERVER["HTTPS"]) && $_SERVER["SERVER_PORT"] === 80) || (isset($_SERVER["HTTPS"]) && $_SERVER["SERVER_PORT"] === 443);
$prefixPort = $usingDefaultPort ? "" : ":" . $_SERVER["SERVER_PORT"];

$prefixHost = $_SERVER["HTTP_HOST"];
$prefixHost = strpos($prefixHost, ":") ? implode(":", explode(":", $_SERVER["HTTP_HOST"], -1)) : $prefixHost;

define("GZIP_PREFIX", "http" . (isset($_SERVER["HTTPS"]) ? "s" : "") . "://" . $prefixHost . $prefixPort . $_SERVER["SCRIPT_NAME"] . "?");


function makeRequest($url) {

  global $anonymize;


  $user_agent = $_SERVER["HTTP_USER_AGENT"];
  if (empty($user_agent)) {
    $user_agent = "Mozilla/5.0 (compatible; Gzip)";
  }
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);


  $browserRequestHeaders = getallheaders();


  $removedHeaders = removeKeys($browserRequestHeaders, array(
    "Accept-Encoding", 
    "Content-Length",
    "Host",
    "Origin"
  ));

  array_change_key_case($removedHeaders, CASE_LOWER);

  curl_setopt($ch, CURLOPT_ENCODING, "");

  $curlRequestHeaders = array();
  foreach ($browserRequestHeaders as $name => $value) {
    $curlRequestHeaders[] = $name . ": " . $value;
  }
  if (!$anonymize) {
    $curlRequestHeaders[] = "X-Forwarded-For: " . $_SERVER["REMOTE_ADDR"];
  }

  if (array_key_exists('origin', $removedHeaders)) {
    $urlParts = parse_url($url);
    $port = $urlParts['port'];
    $curlRequestHeaders[] = "Origin: " . $urlParts['scheme'] . "://" . $urlParts['host'] . (empty($port) ? "" : ":" . $port);
  };
  curl_setopt($ch, CURLOPT_HTTPHEADER, $curlRequestHeaders);

 
  switch ($_SERVER["REQUEST_METHOD"]) {
    case "POST":
      curl_setopt($ch, CURLOPT_POST, true);

      $postData = Array();
      parse_str(file_get_contents("php://input"), $postData);
      if (isset($postData["GzipFormAction"])) {
        unset($postData["GzipFormAction"]);
      }
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    break;
    case "PUT":
      curl_setopt($ch, CURLOPT_PUT, true);
      curl_setopt($ch, CURLOPT_INFILE, fopen("php://input", "r"));
    break;
  }


  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

 
  curl_setopt($ch, CURLOPT_URL, $url);

  
  $response = curl_exec($ch);
  $responseInfo = curl_getinfo($ch);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);


  $responseHeaders = substr($response, 0, $headerSize);
  $responseBody = substr($response, $headerSize);

  return array("headers" => $responseHeaders, "body" => $responseBody, "responseInfo" => $responseInfo);
}


function rel2abs($rel, $base) {
  if (empty($rel)) $rel = ".";
  if (parse_url($rel, PHP_URL_SCHEME) != "" || strpos($rel, "//") === 0) return $rel;
  if ($rel[0] == "#" || $rel[0] == "?") return $base.$rel;
  extract(parse_url($base));
  $path = isset($path) ? preg_replace("#/[^/]*$#", "", $path) : "/";
  if ($rel[0] == "/") $path = "";
  $port = isset($port) && $port != 80 ? ":" . $port : "";
  $auth = "";
  if (isset($user)) {
    $auth = $user;
    if (isset($pass)) {
      $auth .= ":" . $pass;
    }
    $auth .= "@";
  }
  $abs = "$auth$host$port$path/$rel";
  for ($n = 1; $n > 0; $abs = preg_replace(array("#(/\.?/)#", "#/(?!\.\.)[^/]+/\.\./#"), "/", $abs, -1, $n)) {} 
  return $scheme . "://" . $abs;
}


function proxifyCSS($css, $baseURL) {


  $sourceLines = explode("\n", $css);
  $normalizedLines = [];
  foreach ($sourceLines as $line) {
    if (preg_match("/@import\s+url/i", $line)) {
      $normalizedLines[] = $line;
    } else {
      $normalizedLines[] = preg_replace_callback(
        "/(@import\s+)([^;\s]+)([\s;])/i",
        function($matches) use ($baseURL) {
          return $matches[1] . "url(" . $matches[2] . ")" . $matches[3];
        },
        $line);
    }
  }
  $normalizedCSS = implode("\n", $normalizedLines);
  return preg_replace_callback(
    "/url\((.*?)\)/i",
    function($matches) use ($baseURL) {
        $url = $matches[1];
      
        if (strpos($url, "'") === 0) {
          $url = trim($url, "'");
        }
        if (strpos($url, "\"") === 0) {
          $url = trim($url, "\"");
        }
        if (stripos($url, "data:") === 0) return "url(" . $url . ")";
        return "url(" . GZIP_PREFIX . rel2abs($url, $baseURL) . ")";
    },
    $normalizedCSS);
}


function proxifySrcset($srcset, $baseURL) {
  $sources = array_map("trim", explode(",", $srcset)); 
  $proxifiedSources = array_map(function($source) use ($baseURL) {
    $components = array_map("trim", str_split($source, strrpos($source, " "))); 
    $components[0] = GZIP_PREFIX . rel2abs(ltrim($components[0], "/"), $baseURL); 
    return implode($components, " "); 
  }, $sources);
  $proxifiedSrcset = implode(", ", $proxifiedSources);
  return $proxifiedSrcset;
}


if (isset($_POST["GzipFormAction"])) {
  $url = $_POST["GzipFormAction"];
  unset($_POST["GzipFormAction"]);
} else {
  $queryParams = Array();
  parse_str($_SERVER["QUERY_STRING"], $queryParams);
  
  if (isset($queryParams["GzipFormAction"])) {
    $formAction = $queryParams["GzipFormAction"];
    unset($queryParams["GzipFormAction"]);
    $url = $formAction . "?" . http_build_query($queryParams);
  } else {
    $url = substr($_SERVER["REQUEST_URI"], strlen($_SERVER["SCRIPT_NAME"]) + 1);
  }
}
if (empty($url)) {
    if (empty($startURL)) {
      die("<html></html>");
    } else {
      $url = $startURL;
    }
} else if (strpos($url, ":/") !== strpos($url, "://")) {
    $pos = strpos($url, ":/");
    $url = substr_replace($url, "://", $pos, strlen(":/"));
}
$scheme = parse_url($url, PHP_URL_SCHEME);
if (empty($scheme)) {
 
  if (strpos($url, "//") === 0) {
    $url = "http:" . $url;
  }
} else if (!preg_match("/^https?$/i", $scheme)) {
    die('Error: Detected a "' . $scheme . '" URL. Gzip exclusively supports http[s] URLs.');
}


$urlIsValid = count($whitelistPatterns) === 0;
foreach ($whitelistPatterns as $pattern) {
  if (preg_match($pattern, $url)) {
    $urlIsValid = true;
    break;
  }
}
if (!$urlIsValid) {
  die("Error: The requested URL was disallowed by the server adstrator.");
}

$response = makeRequest($url);
$rawResponseHeaders = $response["headers"];
$responseBody = $response["body"];
$responseInfo = $response["responseInfo"];



$responseURL = $responseInfo["url"];
if ($responseURL !== $url) {
  header("Location: " . GZIP_PREFIX . $responseURL, true);
  exit(0);
}


$header_blacklist_pattern = "/^Content-Length|^Transfer-Encoding|^Content-Encoding.*gzip/i";


$responseHeaderBlocks = array_filter(explode("\r\n\r\n", $rawResponseHeaders));
$lastHeaderBlock = end($responseHeaderBlocks);
$headerLines = explode("\r\n", $lastHeaderBlock);
foreach ($headerLines as $header) {
  $header = trim($header);
  if (!preg_match($header_blacklist_pattern, $header)) {
    header($header, false);
  }
}

header("X-Robots-Tag: noindex, nofollow", true);

if ($forceCORS) {



  header("Access-Control-Allow-Origin: *", true);
  header("Access-Control-Allow-Credentials: true", true);

 
  if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
    if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"])) {
      header("Access-Control-Allow-Methods: GET, POST, OPTIONS", true);
    }
    if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"])) {
      header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}", true);
    }
 
    exit(0);
  }

}

$contentType = "";
if (isset($responseInfo["content_type"])) $contentType = $contentType;


if (stripos($contentType, "text/html") !== false) {


  $detectedEncoding = mb_detect_encoding($responseBody, "UTF-8, ISO-8859-1");
  if ($detectedEncoding) {
    $responseBody = mb_convert_encoding($responseBody, "HTML-ENTITIES", $detectedEncoding);
  }

 
  $doc = new DomDocument();
  @$doc->loadHTML($responseBody);
  $xpath = new DOMXPath($doc);

  
  foreach($xpath->query("//form") as $form) {
    $method = $form->getAttribute("method");
    $action = $form->getAttribute("action");

    $action = empty($action) ? $url : rel2abs($action, $url);

    $form->setAttribute("action", rtrim(GZIP_PREFIX, "?"));

    $actionInput = $doc->createDocumentFragment();
    $actionInput->appendXML('<input type="hidden" name="GzipFormAction" value="' . htmlspecialchars($action) . '" />');
    $form->appendChild($actionInput);
  }

  foreach ($xpath->query("//meta[@http-equiv]") as $element) {
    if (strcasecmp($element->getAttribute("http-equiv"), "refresh") === 0) {
      $content = $element->getAttribute("content");
      if (!empty($content)) {
        $splitContent = preg_split("/=/", $content);
        if (isset($splitContent[1])) {
          $element->setAttribute("content", $splitContent[0] . "=" . GZIP_PREFIX . rel2abs($splitContent[1], $url));
        }
      }
    }
  }

  foreach($xpath->query("//style") as $style) {
    $style->nodeValue = proxifyCSS($style->nodeValue, $url);
  }

  foreach ($xpath->query("//*[@style]") as $element) {
    $element->setAttribute("style", proxifyCSS($element->getAttribute("style"), $url));
  }

  foreach ($xpath->query("//img[@srcset]") as $element) {
    $element->setAttribute("srcset", proxifySrcset($element->getAttribute("srcset"), $url));
  }

  $proxifyAttributes = array("href", "src");
  foreach($proxifyAttributes as $attrName) {
    foreach($xpath->query("//*[@" . $attrName . "]") as $element) { 
      $attrContent = $element->getAttribute($attrName);
      if ($attrName == "href" && preg_match("/^(about|javascript|magnet|mailto):/i", $attrContent)) continue;
      $attrContent = rel2abs($attrContent, $url);
      $attrContent = GZIP_PREFIX . $attrContent;
      $element->setAttribute($attrName, $attrContent);
    }
  }


  $head = $xpath->query("//head")->item(0);
  $body = $xpath->query("//body")->item(0);
  $prependElem = $head != NULL ? $head : $body;



  echo "" . $doc->saveHTML();
} else if (stripos($contentType, "text/css") !== false) { 
  echo proxifyCSS($responseBody, $url);
} else { 
  header("Content-Length: " . strlen($responseBody), true);
  echo $responseBody;
}
?>
