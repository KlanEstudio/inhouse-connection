<?php

// Configuración del sistema

// Id de dominio
$id_dominio = 0;

// Token de autorización;
$token = '';

// Formato de fecha
$formatofecha = 'd/m/Y h:i a';

// Utilizar cache del sitio
$usecache = false;

// Tiempo en minutos que permanece el cache
$cache_min = 5;

/***********************************************************************************************
 * Definición del funcionamiento
 ***********************************************************************************************/ 

class InHouse {
	
	protected $caller;
	
	protected $media_url = 'http://media.admininhouse.com/';
	
	protected $valores = array();
		
	protected $connection = true;
	
	public function __construct($id_dominio, $token, $formatofecha)
	{
		$this->caller = new Caller($id_dominio, $token, $formatofecha);
	}
	
	public function parse_html($contents) {
        $contents = str_replace("\r\n","\n", $contents);
		$contents = str_replace("\r","\n", $contents);

		preg_match_all('/{custom:(\w+(\(\w+\))?)}/i', $contents, $vars);
		
		foreach($vars[1] as $var) {
			$custom = explode('(', trim($var, ')'));
			$name = $custom[0];
			$file = $name.'.php';
			if(file_exists($file)) {
				if(!class_exists($name)) {
					include $file;
				}
				if(isset($custom[1])) {
					$object = new $name($this->caller, $custom[1]);
				}
				else {
					$object = new $name($this->caller);
				}
				$contents = str_replace('{custom:'.$var.'}', (string) $object, $contents);
			}
		}
		
		preg_match_all('/{(\w+:\w+:\w+)}/i', $contents, $vars);
		
		foreach($vars[1] as $var) {			
			$info = explode(':', $var);
			
			$contents = str_replace( '{'.$var.'}', $this->getData($info[0], $info[1], $info[2]), $contents);
		}
		
		preg_match_all('/<!--#\s?inh:(\w+\([\d+|\w+:\w+:\w+]+(,\d+)?\))\s?-->\n(<[^!]|[^<])*<!--#\s?close:inh\s?-->/i', $contents, $vars, PREG_SET_ORDER );
		
		foreach($vars as $plantilla) {
			$contents = str_replace($plantilla[0], $this->processTemplate($plantilla[0], $plantilla[1]), $contents);
		}
		
		return $contents;
	}
	
	protected function getData($modulo, $campo, $id) {
		if($this->connection === false) {
			return 'forbidden';
		}
		
		switch($modulo) {
			case 'imagen':
			case 'video':
			case 'banner':
				$modulo = 'media';
				break;
		}
		
		$intid = (int) $id;
		if($intid == 0) {
			$id = isset($_GET[$id])?$_GET[$id]:NULL;
		}
		if(is_null($id)) {
			return 'forbidden';
		}
		
		if(!isset($this->valores[$modulo][$id])) {
			$data = $this->caller->get_json($this->caller->construct_call($modulo, $id));
			if($data->auth == false) {
				$this->connection = false;
				return '';
			}
			$this->valores[$modulo][$id] = $data->$modulo;			
		}
		
		if($campo == 'id_galeria' || $campo == 'portada_thumb' || $campo == 'portada_imagen') {
			$field = $campo;
		}
		elseif($campo == 'portada') {
			$field = 'portada_thumb';
		}
		elseif($campo == 'imagen') {
			$field = 'ruta_media';
		}
		elseif($campo == 'video' || $campo == 'enlace' && $modulo == 'media') {
			$field = 'media_media';
		}
		elseif($modulo == 'infogaleria') {
			$field = $campo.'_galeria';
		}
		else {
			$field = $campo.'_'.$modulo;
		}
										
		$valor = isset($this->valores[$modulo][$id]->$field)?$this->valores[$modulo][$id]->$field:'';
		
		if($field == 'portada_thumb' || $field == 'portada_imagen' || $field == 'ruta_media' || $field == 'thumb_media') {
			$valor = $this->media_url.$valor;
		}
		
		if($campo == 'fecha') {
			$valor = $this->caller->formatFecha($valor);
		}
		
		return $valor;
	}
	
	protected function processTemplate($original, $identificador) {
		if($this->connection === false) {
			return 'forbidden';
		}
		
		list($modulo, $id) = explode('(', trim($identificador, ')'));
		
		$total = 0;
		if(strpos($id, ',') !== false) {
			$limit = explode(',', $id);
			$id = $limit[0];				
			$total = $limit[1];
		}
		
		if((int) $id == 0) {
			$conf = explode(':', $id);
			
			if(count($conf) == 3) {
				$id = $this->getData($conf[0], $conf[1], $conf[2]);
			}
			else {
				$id = isset($_GET[$id])?$_GET[$id]:0;
			}
		}
				
		if($modulo == 'banners' || $modulo == 'banner' || $modulo == 'videos' || $modulo == 'imagenes') {
			$modulo = 'medias';
		}
		
		if(!isset($this->valores[$modulo][$id])) {
			$data = $this->caller->get_json($this->caller->construct_call($modulo, $id));
			$this->valores[$modulo][$id] = $data;
			if($data->auth == false) {
				$this->connection = false;
				return 'forbidden';
			}
		}
		
		preg_match_all('/{(\w+)}/i', $original, $vars);
		
		if(!isset($this->valores[$modulo][$id]->tipo)) {
			return '';
		}
		
		$tipo = $this->valores[$modulo][$id]->tipo;
		$coleccion = '';
		$array = $this->valores[$modulo][$id]->$modulo;
		
		$pag = isset($_GET['pag'])?$_GET['pag']:1;
		$init = ($pag - 1)*$total;
		
		$total = $total * $pag;
		
		if($total == 0 || $total > count($array)) {
			$total = count($array);
		}
		
		for($i = $init; $i < $total; $i++) {
			$elemento = $array[$i]; 
			$contenido = $original;
			$id = 'id_'.$tipo;
			$this->valores[$tipo][$elemento->$id] = $elemento;
						
			foreach($vars[1] as $var) {
				$contenido = preg_replace( '/\{'.$var.'}/s', $this->getData($tipo, $var, $elemento->$id), $contenido);
			}
			$coleccion .= $contenido;
		}
		
		return $coleccion;
	}
}

class Caller 
{
	protected $dominio;
	
	protected $token;
	
	protected $id_dominio;
	
	protected $formato_fecha;
	
	protected $api_url = 'http://api.admininhouse.com/';
	
	public function __construct($id_dominio, $token, $formatofecha)
	{
		$this->dominio = $_SERVER['SERVER_NAME'];
		$this->id_dominio = $id_dominio;
		$this->token = $token;
		$this->formatofecha = $formatofecha;
	}
	
	protected function api_call($api_call)
	{
		$curl = curl_init($api_call);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_REFERER, $this->dominio);
		$result = curl_exec($curl);
		curl_close($curl);
	
		if($result === false)
		{
			throw new Exception('Fallo la operación de CURL');
		}
	
		return $result;
	}
	
	public function get_json($api_call)
	{
		$data = json_decode($this->api_call($api_call));
		if($data === false) {
			switch(json_last_error()) {
				case JSON_ERROR_NONE: $error = 'sin errores';
				break;
				case JSON_ERROR_DEPTH: $error = 'alcazada profundidad máxima';
				break;
				case JSON_ERROR_STATE_MISMATCH: $error = 'json inválido o mal formado';
				break;
				case JSON_ERROR_CTRL_CHAR: $error = 'caracter de control inesperado';
				break;
				case JSON_ERROR_SYNTAX: $error = 'error de sintaxis';
				break;
				case JSON_ERROR_UTF8: $error = 'codificacion incorrecta de caracteres';
				break;
				default: $error = 'error desconocido';
			}
			throw new Exception('No se pudo decodificar JSON ('.$error.')');
		}
		return $data;
	}
	
	public function construct_call($modulo, $id) {
		$call = $this->api_url.'?sec=api&response='.$modulo.'&token='.$this->token.'&id_dominio='.$this->id_dominio.'&dominio='.$this->dominio.'&id='.$id.'&view=json';
		return $call;
	}
	
	public function formatFecha($fecha)
	{
		return date($this->formatofecha, strtotime($fecha));
	}
}


$inh = new InHouse($id_dominio, $token, $formatofecha);

$request = isset($_SERVER['PATH_INFO'])?$_SERVER['PATH_INFO']:'';
if($request == '') {
	$uri = isset($_SERVER['REQUEST_URI'])?$_SERVER['REQUEST_URI']:'';
	list($request) = explode('?', $uri);
}
$request = trim($request, '/');

$request = str_replace('.php', '.html', $request);

if($request == '') {
	$request = 'index.html';
}

if(!file_exists($request)) {
	header("HTTP/1.0 404 Not Found");
	header('Status: 404 Not Found');
	header('Content-type: text/html; charset=UTF-8');
	echo 'Página no encontrada: '.$request;
}
else {
	$contents = file_get_contents($request);
	
	try {
		$renew_data = false;
		$ext = pathinfo($request, PATHINFO_EXTENSION);
		$get = http_build_query($_GET);
		$cache = '__cache/'.str_replace('.'.$ext,'__'.$get.'__cache.'.$ext, $request);
		if(file_exists($cache)) {
			$changed = filemtime($cache);
			if(time() - $changed > 60*$cache_min) {
				$renew_data = true;
			}
		}
		else {
			$renew_data = true;
		}
		if($renew_data === false && $usecache === true) {
			$contenido = file_get_contents($cache);
		}
		else {
			$contenido = $inh->parse_html($contents);
		} 
		if(is_writable('.')) {
			if(!file_exists('./__cache')) {
				mkdir('__cache', 0755);
			}
			
			if($renew_data == true) {
				$dir = dirname($cache);
				if(!file_exists($dir)) {
					mkdir($dir, 0755, true);
				}
				file_put_contents($cache, $contenido);
			}
		}
		echo $contenido;
	}
	catch(Exception $e) {
		if(file_exists($cache)) {
			echo file_get_contents($cache);
		}
		else {
			echo 'error de conexi&oacute;n con InHouse';
		}
	}
}