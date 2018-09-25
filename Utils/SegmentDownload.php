<?php
/* This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

###############################################################################
/*
 * This PHP script is responsible for downloading segment(s)
 * pointed to by the MPD.
 * @name: SegmentDownload.php
 * @entities: 
 *      @functions{
 *          download_data($directory, $array_file),
 *          remote_file_size2($url),
 *          partial_download($url, $begin, $end, &$ch)
 *      }
 */
###############################################################################

/*
 * Download segments
 * @name: download_data
 * @input: $directory - download directory for the segment(s)
 *         $array_file - URL of the segment(s) to be downloaded
 * @output: $file_sizearr - array of original size(s) of the segment(s)
 */
function download_data($directory, $array_file, $is_subtitle_rep){
    global $session_dir, $progress_report, $progress_xml, $reprsentation_mdat_template, $missinglink_file, $current_adaptation_set, $current_representation;
    
    $mdat_file = str_replace(array('$AS$', '$R$'), array($current_adaptation_set, $current_representation), $reprsentation_mdat_template);
    $sizefile = open_file($session_dir . '/' . $mdat_file . '.txt', 'a+b'); //create text file containing the original size of Mdat box that is ignored
    $initoffset = 0; // Set pointer to 0
    $totaldownloaded = 0; // bytes downloaded
    $totalDataProcessed = 0; // bytes processed within segments
    $totalDataDownloaded = 0;
    $downloadMdat=0;
    
    # Initialize curl object
    $ch = curl_init();
    
    # Iterate over $array_file
    $mdat_index = 0;
    for ($index = 0; $index < sizeof($array_file); $index++){
        $filePath = $array_file[$index]; //get segment URL
        $file_size = remote_file_size2($filePath); // Get actual data size
        if ($file_size === false){ // if URL return 404 report it as broken url
            $missing = open_file($session_dir . '/' . $missinglink_file . '.txt', 'a+b');
            fwrite($missing, $filePath . "\n");
            error_log("downloaddata_Missing:" . $filePath);
        }
        else{
            $sizepos = 0;
            
            # Store the original size of segments
            $file_sizearr[$index] = $file_size; 
            
            # Get the name of segment
            $tok = explode('/', $filePath);
            $filename = $tok[sizeof($tok) - 1]; 
            
            # Iterate over the segment content
            while ($sizepos < $file_size){
                $location = 1; // temporary pointer
                $name = null; // box name
                $size = 0; // box size
                $newfile = open_file($directory . $filename, 'a+b'); // create an empty mp4 file to contain data needed from remote segment
                
                # Download the partial content and unpack
                $content = partial_download($filePath, $sizepos, $sizepos + 1500, $ch);
                $byte_array = unpack('C*', $content);
                
                # Update the total size of downloaded data
                $totalDataDownloaded = $totalDataDownloaded + 1500; 
                
                # Assure that the pointer doesn't exceed size of downloaded bytes
                while ($location < sizeof($byte_array)){
                    $size = $byte_array[$location] * 16777216 + $byte_array[$location + 1] * 65536 + $byte_array[$location + 2] * 256 + $byte_array[$location + 3];
                    if (sizeof($array_file) === 1){ // if presentation contain only single segment
                        $totaldownloaded = $totaldownloaded + $size;   // total data being processed 
                        $percent = (int) (100 * $totaldownloaded / $file_size); //get percent over the whole file size
                    }
                    else
                        $percent = (int) (100 * $index / (sizeof($array_file) - 1)); // percent of remaining segments
                    
                    $name = substr($content, $location + 3, 4); //get box name exist in the next 4 bytes from the bytes containing the size
                    if ($name != 'mdat'){
                        # If it is not mdat box download it
                        # The total size being downloaded is location + size
                        # If the amount of byte processed < the data downloaded at begining
                        #   Copy the whole data to the new mp4 file
                        # Else 
                        #   Download the rest of the box from the remote segment
                        #   Copy the rest to the file
                        $total = $location + $size;
                        if ($total < sizeof($byte_array))
                            fwrite($newfile, substr($content, $location - 1, $size));
                        else{
                            $rest = partial_download($filePath, $sizepos, $sizepos + $size - 1, $ch); 
                            $totalDataDownloaded = $totalDataDownloaded + $size - 1;
                            fwrite($newfile, $rest);
                        }
                    }
                    else
                    {
                        # If it is mdat box
                        # If mdat downloading is chosen
                        #   Stuff complete mdat data with zeros
                        # Else
                        #   Add the original size of the mdat to text file without the name and size bytes(8 bytes)
                        #   Copy only the mdat name and size to the segment
                        if($downloadMdat){
                            fwrite($sizefile, ($initoffset + $sizepos + 8) . " " . 0 . "\n");
                            fwrite($newfile, substr($content, $location - 1, 8));
                            fwrite($newfile,str_pad("0",$size-8,"0"));
                        }
                        else{
                            fwrite($sizefile, ($initoffset + $sizepos + 8) . " " . ($size - 8) . "\n");
                            fwrite($newfile, substr($content, $location - 1, 8));
                            
                            ## For DVB subtitle checks related to mdat content
                            ## Save the mdat boxes' content into xml files
                            if($is_subtitle_rep){
                                $subtitle_xml_string = '<subtitle>';
                                $mdat_file = $directory . 'Subtitles/' . $mdat_index . '.xml';
                                fopen($mdat_file, 'w');
                                chmod($mdat_file, 0777);
                                $mdat_index++;
                                 $total = $location + $size;
                                if ($total < sizeof($byte_array)){
                                    $text = substr($content, ($initoffset + $location + 7), ($size - 7));
                                    $text = substr($text, strpos($text, '<tt'));
                                    $subtitle_xml_string .= $text;
                                    //fwrite($mdat_file, substr($content, ($initoffset + $sizepos + 8), ($size - 1)));
                                }
                                else{
                                    $rest = partial_download($filePath, $sizepos+8, $sizepos + $size - 1, $ch);
                                    $text = $rest;
                                    $text = substr($text, strpos($text, '<tt'));
                                    $subtitle_xml_string .= $text;
                                    //fwrite($mdat_file, $rest);
                                }
                                $subtitle_xml_string = substr($subtitle_xml_string, 0, strrpos($subtitle_xml_string, '>')+1);
                                $subtitle_xml_string .= '</subtitle>';
                                $mdat_data = simplexml_load_string($subtitle_xml_string);
                                $mdat_data->asXML($mdat_file);
                            }
                        }
                    }
                    
                    $sizepos = $sizepos + $size; // move size pointer
                    $location = $location + $size; // move location pointer
                }
                
                # Modify node and sav it to a progress report
                $progress_xml->Progress->percent = strval($percent);
                $progress_xml->Progress->dataProcessed = strval($totalDataProcessed + $sizepos);
                $progress_xml->Progress->dataDownloaded = strval($totalDataDownloaded);
                $progress_xml->asXml(trim($session_dir . '/' . $progress_report));
            }

            fflush($newfile);
            fclose($newfile);
            
            # Update initial offset after processing the whole file
            # Update data processed
            $initoffset = $initoffset + $file_size;
            $totalDataProcessed = $totalDataProcessed + $file_size;  
        }
    }
    
    # All done
    curl_close($ch);
    
    fflush($sizefile);
    fclose($sizefile);
    
    fflush($missing);
    fclose($missing);
    
    if (!isset($file_sizearr))
        $file_sizearr = 0;
    
    chmod($session_dir . '/' . $mdat_file . '.txt', 0777);
    return $file_sizearr;
}

/*
 * Get the size of the segment remotely without downloading it
 * @name: remote_file_size2
 * @input: $url - URL of the segment of which the size is requested
 * @output: FALSE or segment size
 */
function remote_file_size2($url){
    $return_val = FALSE;
    
    # Get all header information
    $data = get_headers($url, true);
    if ($data[0] === 'HTTP/1.1 404 Not Found' || 
        $data[0] === 'HTTP/1.0 404 Not Found' || 
        $data[0] === 'HTTP/2 404 Not Found')
        return $return_val;
    
    # Look up validity
    if (isset($data['Content-Length']))
        return (int) $data['Content-Length'];
    else
        return $return_val;
}

/*
 * Download partial bytes of a file by giving file location, start and end byte
 * @name: partial_download
 * @input: $url - URL of the segment of which the size is requested
 *         $begin - byte to start from
 *         $end - byte to end at
 *         $ch - curl object
 * @output: downloaded content
 */
function partial_download($url, $begin, $end, &$ch){
    global $session_dir;
    
    # Temperoray container for partial segments downloaded
    $temp_file = $session_dir . '//' . "getthefile.mp4";
    
    $fp = open_file($temp_file, "w+");
    if (!$fp){
        error_log("fopen:.exit!");
        exit;
    }
    
    # Add curl options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 500);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
    $range = $begin . '-' . $end;
    curl_setopt($ch, CURLOPT_RANGE, $range);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    
    # Execute the curl request
    curl_exec($ch);
    if (curl_errno($ch))
        error_log("curl_errno:" . curl_errno($ch));
    
    # Check the downloaded content
    $content = file_get_contents($temp_file);
    if (!$content)
        error_log("file_get_contents:failed" . $url . "/" . $begin . "/" . $end);
    
    fclose($fp);
    
    # Return
    return $content;
}