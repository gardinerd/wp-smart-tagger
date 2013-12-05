<?php
/*
Plugin Name: WP Smart Tagger
Original Plugin URI: http://blinger.org/wordpress-plugins/auto-tagger/
Description: This is a fork from WP Auto Tagger to support the latest Yahoo API changes and Wordpress Versions. Automatically finds tags based on your post content.
Version: 2.0.0
Author: Creator11
Author URI: https://github.com/creator11/wp-smart-tagger
*/

/*  Copyright 2008  

    Dan Gardiner  (email : mrdgardiner@gmail.com)
    Saurabh Gupta  (email : saurabh0@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Add sidebar button
add_action('dbx_post_sidebar', 'tagger_sidebar', 1);
function tagger_sidebar() {
	global $post_ID;
	wp_print_scripts( array( 'sack' ));    
	?>
	<script type="text/javascript">
	//<![CDATA[
	jQuery(document).ready( function() {
		jQuery( '#tagsdiv' ).append( jQuery( '#smarttaggerdiv' ) );
	} );
	
	function tagger_gettags( )
	{
		var form = document.getElementById('post');
		if ( (typeof tinyMCE != "undefined") && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden() )
		{
		   tinyMCE.triggerSave();
		}
		if(form.post_title.value.length==0 || form.content.value.length==0) {
          alert("Please enter some content first");
          return;
		}
		var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php" );
		mysack.execute = 1;
		mysack.method = 'POST';
		mysack.setVar( "action", "gettags" );
		mysack.setVar( "postid", "<?php echo $post_ID; ?>" );
		mysack.setVar( "tags", form.tags_input.value );
		mysack.setVar( "title", form.post_title.value);
		mysack.setVar( "content", form.content.value );
		mysack.encVar( "cookie", document.cookie, false );
		mysack.onError = function() { alert('AJAX error in getting tags' )};
		document.getElementById('gettags').disabled=true;
		mysack.runAJAX();
		return true;
	}
	function tagger_showtags( tags )
	{
		//alert(tags);
        jQuery('#tags-input').val(tags);
		document.getElementById('gettags').disabled=false;
		tag_update_quickclicks();
	}
	//]]>
	</script>
		<div id="smarttaggerdiv">
			<h3 class="dbx-handle">Smart Tagger</h3>
			<div class="dbx-content">
			<input type="hidden" name="smarttagger" value="1" />
			<button id="gettags" class="button" onclick="tagger_gettags(); return false;" style="float: right">Suggest Tags</button>
			<label for="smarttag" class="selectit"><input type="checkbox" tabindex="2" id="smarttag" name="smarttag" value="yes" <?php if(get_option('smarttag')=='yes') echo 'checked="checked"'; ?> /> Smart-tag post on save</label><br />
			<small>Smart Tagger will not replace existing tags. For tag suggestions without saving click 'Suggest Tags'.</small>
			</div>
		</div>
	<?php
}

// Register post insert hook
add_action('wp_insert_post', 'smart_gettags', 10, 2);
function smart_gettags($post_id, $post) {
	if(isset($_POST['smarttagger'])) update_option('smarttag',$_POST['smarttag']);
	//print_r($post); exit;
	if(get_option('smarttag')=='yes') {
		$tags=$post->tags_input;
		if(is_array($tags)) $tags=implode(',',$tags);
		if(empty($tags) && !empty($_POST['tags_input'])) $tags=$_POST['tags_input'];
		$tags=gettags($post->post_title,$post->post_content,$tags);
		if(!is_array($tags)) return;
		wp_add_post_tags($post_id,$tags);
	}
}

// Register AJAX action
add_action('wp_ajax_gettags', 'ajax_gettags' );
function ajax_gettags() {
	$tags=gettags($_POST['title'],$_POST['content'],$_POST['tags']);
	if(!is_array($tags)) die("alert('".$tags."')");
	// Compose JavaScript for return
	die( "tagger_showtags('" . tagger_ajax_escape(implode(',',$tags)) . "')" );
}
function gettags($title,$content,$tags) {
	$content = preg_replace('/[^a-zA-Z0-9_ %\[\]\.\(\)%&-]/s', ' ', "$title $content");
	$content = trim(preg_replace('/\s+/', ' ', $content));

	if(strlen($tags)) {
		$subject=$tags;
	} else {
		$subject=$title;
    	}

	if(!function_exists('curl_init')) return 'cURL not available';
   	$yql = 'http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20contentanalysis.analyze%20where%20text%3D%22'.urlencode($content).'%22&format=json&callback=';

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $yql);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$response = curl_exec($ch);
	$results = json_decode($response);
	$yqltags = array();
	
	if (is_array($results->query->results->entities->entity)) {
		foreach ($results->query->results->entities->entity as &$item){
   			array_push($yqltags, $item->text->content);
		}
	} else {
		return; 
	}

	if(curl_errno($ch)) return curl_error($ch);
	curl_close($ch);

	$tags = explode(',',$tags);	 
	$tags=array_merge($tags, $yqltags);
	array_walk($tags,create_function('&$value','$value = tagger_proper_case(trim($value));'));
	$tags = array_unique($tags);
	if(in_array('',$tags)) unset($tags[array_search('',$tags)]); // remove blanks    

	return $tags;
}

register_activation_hook(__FILE__,'tagger_activate');
function tagger_activate() {
	// Set defaults
	update_option('smarttag','yes');
}

/**
* Escapes a string so it can be safely echo'ed out as Javascript
*
* @param  string $str String to escape
* @return string      JS Safe string
*/
function tagger_ajax_escape($str)
{
    $str = str_replace(array('\\', "'"), array("\\\\", "\\'"), $str);
    $str = preg_replace('#([\x00-\x1F])#e', '"\x" . sprintf("%02x", ord("\1"))', $str);

    return $str;
}
function tagger_proper_case($input) {
  return preg_replace_callback('|\b[a-z]|',create_function('$matches','return strtoupper($matches[0]);'),$input);
}
