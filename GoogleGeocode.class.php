<?php
/**
* Copyright (c) 2010 Nabeel Shahzad
*
* Permission is hereby granted, free of charge, to any person obtaining a copy
* of this software and associated documentation files (the "Software"), to deal
* in the Software without restriction, including without limitation the rights
* to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
* copies of the Software, and to permit persons to whom the Software is
* furnished to do so, subject to the following conditions:
*
* The above copyright notice and this permission notice shall be included in
* all copies or substantial portions of the Software.

* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
* IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
* AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
* LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
* OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
* THE SOFTWARE.
*
* @author Nabeel Shahzad
* @copyright Copyright (c) 2009 - 2010, Nabeel Shahzad
* @link http://github.com/nshahzad/Google-Geocoder
* @license MIT License
* 
* Error Codes:
* http://code.google.com/apis/maps/documentation/javascript/v2/reference.html#GGeoStatusCode
*/

class GoogleGeocode 
{
	public $errno = 0;
	public $error = '';
	public $query_url = '';

	public $parsed_data = null;
	public $throw_exceptions = true;
	public $error_codes = array(
		'200' => 'G_GEO_SUCCESS',
		'400' => 'G_GEO_BAD_REQUEST',
		'500' => 'G_GEO_SERVER_ERROR',
		'601' => 'G_GEO_MISSING_QUERY',
		'602' => 'G_GEO_UNKNOWN_ADDRESS',
		'603' => 'G_GEO_UNAVAILABLE_ADDRESS',
		'604' => 'G_GEO_UNKNOWN_DIRECTIONS',
		'610' => 'G_GEO_BAD_KEY',
		'620' => 'G_GEO_TOO_MANY_QUERIES',
	);

	protected $key = null;
	protected $curl = null;
	protected $geocode_url = 'http://maps.google.com/maps/geo?';

	/**
	 * Startup this class... pass in your Google API Key
	 *
	 * @param string $key Your Google API Key
	 * @return nothing
	 *
	 */
	public function __construct($key)
	{
		# Need cURL to use this!
		if(!function_exists('curl_init'))
		{
			return false;
		}

		$this->key = $key;
		$this->curl = curl_init();

		curl_setopt($this->curl, CURLOPT_TIMEOUT, 180);
		curl_setopt($this->curl, CURLOPT_HEADER, 0);
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
	}

	public function __destruct()
	{
		curl_close($this->curl);
	}

	/**
	 * Return the last error number
	 * 
	 * @return integer Return the error code from the Google service
	 */
	public function errno()
	{
		return $this->errno;
	}

	/**
	 * Return the last error code
	 * 
	 * @return string The Google-specific error code message
	 */
	public function error() 
	{
		return $this->error;
	}

	/**
	 * Get the URL of the last query
	 * 
	 * @return string URL
	 */
	public function query_url()
	{
		return $this->query_url;
	}

	/**
	 * Run a search against the Geocoder API, and return an
	 * object, or false if there's an error
	 * 
	 * @param string $query The address or name to search for. Be as specific as possible (include state, country)
	 * @return mixed Returns an object or false
	 */
	public function search($params)
	{
		$default_params = array(
			'q' => '',
			'region' => 'US',
			'language' => 'en',
			'sensor' => 'false',
			'oe' => 'utf8',
		);

		# If they passed an array of options, otherwise just set
		# it to the defaults above
		if(is_array($params))
		{
			# Form the URL, with the query + code the code
			$params['q'] = urlencode(trim($params['q']));
			$params = array_merge($default_params, $params);
		}
		else
		{
			$default_params['q'] = urlencode(trim($params));
			$params = $default_params;
		}
		
		# Somebody set us up the bomb
		$this->query_url = $this->geocode_url.http_build_query($params).'&output=json&key='.$this->key;
		curl_setopt($this->curl, CURLOPT_URL, $this->query_url);

		$data = curl_exec($this->curl);
		$this->errcode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

		if($this->errcode == '200')
		{
			$this->error = $this->error_codes[200];
			return $this->parse_results($data);
		}

		$this->error = $this->error_codes[$this->errcode];

		return false;
	}

	/**
	 * Parse the results returned from the Google API
	 *
	 * @param string $data JSON String returned from the Google API
	 * @return object Object with the Geocoder information
	 *
	 */
	protected function parse_results($data)
	{
		# Decode it, comes from Java
		$this->parsed_data = json_decode($data);
	
		# Make sure there are results in there
		$total_count = count($this->parsed_data->Placemark);
		if($total_count == 0)
		{
			return false;
		}

		$return = new stdClass;
		for($i = 0; $i < $total_count; $i++)
		{
			if(empty($return->lat))
			{
				$return->lat = $this->parsed_data->Placemark[$i]->Point->coordinates['0'];
				$return->lng = $this->parsed_data->Placemark[$i]->Point->coordinates['1'];
			}

			if(empty($return->address))
			{
				$return->address = $this->parsed_data->Placemark[$i]->address;
			}

			if(empty($return->state))
			{
				$return->state = $this->parsed_data->Placemark[$i]->AddressDetails->Country
									->AdministrativeArea->AdministrativeAreaName;
			}

			# Get the city, see if it's an alternate field, if it hasn't been found
			if($return->city == '')
			{
				$return->city = $this->parsed_data->Placemark[$i]->AddressDetails->Country
								->AdministrativeArea->SubAdministrativeArea
								->Locality->LocalityName ;

				if($return->city == '')
				{
					$return->city = $this->parsed_data->Placemark[$i]->AddressDetails
									->Country->AdministrativeArea->Locality->LocalityName;
				}
			}

			# Same as above with zip
			if($return->zip == '')
			{
				$return->zip = $this->parsed_data->Placemark[$i]->AddressDetails->Country
								->AdministrativeArea->SubAdministrativeArea
								->Locality->PostalCode->PostalCodeNumber;

				if($return->zip == '')
				{
					$return->zip = $this->parsed_data->Placemark[$i]->AddressDetails->Country
									->AdministrativeArea->Locality->PostalCode->PostalCodeNumber;
				}
			}
		}

		return $return;
	}
}