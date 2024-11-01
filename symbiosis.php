<?php

/*
Plugin Name: Symbiosis
Plugin URI: http://wordpress.org/extend/plugins/symbiosis/
Description: A plugin to enable sensible content separation. Creates and manages separate top-level categories for each user, allowing the user to add both posts and links only to the aforementioned categories.
Author: Julius Juurmaa
Version: trunk
Author URI: http://ogion.pri.ee/
*/

/*
Copyright 2008 Julius Juurmaa (e-mail: klimbermann@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301 USA
*/

// things that need to be done while activating
function symbiosis_activate() {
	global $wpdb;
	$users = $wpdb->get_results("SELECT ID, display_name FROM $wpdb->users");
	foreach($users as $user) {
    symbiosis_update_term($user->ID, 'category', $user->display_name);
    symbiosis_update_term($user->ID, 'link_category', $user->display_name);
  }
}

// things that need to be done while deactivating
function symbiosis_deactivate() {
	global $wpdb;
	$users = $wpdb->get_results("SELECT ID FROM $wpdb->users");
	foreach($users as $user) {
  	delete_usermeta($user->ID, 'symbiosis_default');
    delete_usermeta($user->ID, 'symbiosis_category');
    delete_usermeta($user->ID, 'symbiosis_link_category');
  }
}

// what to do when user updates his/her profile
function symbiosis_update_user($id) {
  $user = get_userdata($id);
  symbiosis_update_term($id, 'category', $user->display_name);
  symbiosis_update_term($id, 'link_category', $user->display_name);
}

// what to do when a user is deleted
function symbiosis_delete_user($id) {
  symbiosis_update_term($id, 'category');
  symbiosis_update_term($id, 'link_category');
}

// modify user interface
function symbiosis_update_forms($input) {
	if(strpos($_SERVER['REQUEST_URI'], '/wp-admin/post.php') !== false ||
		 strpos($_SERVER['REQUEST_URI'], '/wp-admin/post-new.php') !== false) {
		ob_start(symbiosis_replace_post);
	}
  elseif(strpos($_SERVER['REQUEST_URI'], '/wp-admin/link.php') !== false ||
		     strpos($_SERVER['REQUEST_URI'], '/wp-admin/link-add.php') !== false) {
		ob_start(symbiosis_replace_link);
	}
  elseif(strpos($_SERVER['REQUEST_URI'], '/wp-admin/options-writing.php') !== false) {
		ob_start(symbiosis_replace_options);
	}
	return $input;
}

// check the data before saving categories
function symbiosis_post_save($input) {
  $home = get_usermeta($_POST['post_author'], 'symbiosis_category');
  $output = array();
  foreach($input as $item) {
    if(symbiosis_category_root($item) == $home) {
      $output[] = $item;
    }
  }
  if(count($output) == 0) {
    $output[] = get_usermeta($_POST['post_author'], 'symbiosis_default');
  }
  return $output;
}

// correct link categories after save
function symbiosis_link_save($id) {
  $link = get_bookmark($id);
  $category = get_usermeta($link->link_owner, 'symbiosis_category');
  wp_set_link_cats($id, array($category));
}

// helper function to modify link add/edit interface
function symbiosis_replace_link($input) {
  return preg_replace('#<div id="linkcategorydiv"[^>]*>(.*?</div>){4}\s*</div>#sim', '', $input);
}

// helper function to modify post add/edit interface
function symbiosis_replace_post($input) {
  if(strpos($_SERVER['REQUEST_URI'], '/wp-admin/post.php') !== false) {
    $home = get_usermeta($GLOBALS['post']->post_author, 'symbiosis_category');
  }
  else {
    $home = $GLOBALS['userdata']->symbiosis_category;
  }
  $filter = array();
  $categories =& get_categories('hide_empty=0');
  foreach($categories as $category) {
    if(symbiosis_category_root($category->cat_ID) != $home && $category->cat_ID != $home) {
      $filter[] = $category->cat_ID;
    }
  }
  $filter = implode('|', $filter);
  if(strpos($filter, '|') !== false) {
    $filter = '('. $filter .')';
  }
  $patterns = array(
    '#<div id="tagsdiv"([^>]*)>#i',
    '#<li id="(popular-)?category-'. $filter .'"[^>]*>.*?(<ul>.*?</ul>.*?)?</li>#sim',
    "#<option value='-1'>.*?</option>#sim",
    '#<option value="'. $filter .'">.*?</option>#sim'
  );
  return preg_replace($patterns, array('<div id="tagsdiv"$1 style="display:none;">'), $input);
}

// helper function to modify main settings page
function symbiosis_replace_options($input) {
  return preg_replace(
    '#<select[^>]*id="default_(link_|)category"[^>]*>.*?</select>#sim',
    __('Default categories are specified at user level. Disable the Symbiosis plugin to enforce site-wide settings.'),
    $input
  );
}

// helper function to update terms in database
function symbiosis_update_term($user_id, $taxonomy, $user_name = null) {
  $current = get_usermeta($user_id, 'symbiosis_'. $taxonomy);
  $data['name'] = $user_name;
  if(!empty($current)) {
    if(is_null($user_name)) {
      wp_delete_term($current, $taxonomy);
    }
    else {
      wp_update_term($current, $taxonomy, $data);
    }
  }
  elseif(!is_null($user_name)) {
    $exists = get_term_by('name', $user_name, $taxonomy);
    if(!empty($exists)) {
      $new['term_id'] = $exists->term_id;
    }
    else {
      $new = wp_insert_term($user_name, $taxonomy, $data);
    }
    update_usermeta($user_id, 'symbiosis_'. $taxonomy, $new['term_id']);
    update_usermeta($user_id, 'symbiosis_default', $new['term_id']);
  }
}

// helper function to get category root
function symbiosis_category_root($parent) {
  do {
    $id = $parent;
    $category =& get_category($parent);
    $parent = $category->category_parent;
  } while($parent != 0);
  return $id;
}

// add options to user profile
function symbiosis_add_options() {
  global $user_id;
  $home = get_usermeta($user_id, 'symbiosis_category');
  $current = get_usermeta($user_id, 'symbiosis_default');
  $categories =& get_categories('hide_empty=0');
  $filter = array();
  foreach($categories as $category) {
    if(symbiosis_category_root($category->cat_ID) != $home) {
      $filter[] = $category->cat_ID;
    }
  }
  $filter = implode(',', $filter);
  echo '<table class="form-table"><tr>
		<th scope="row">'. __('Default Posts Category') .'</th>
		<td>'. wp_dropdown_categories(array(
      'hide_empty' => 0,
      'name' => 'symbiosis_default',
      'orderby' => 'name',
      'hierarchical' => 1,
      'tab_index' => 3,
      'exclude' => $filter,
      'echo' => false,
      'selected' => $current
    )) .'</td></tr></table>';
}

// save user profile options to database
function symbiosis_update_options() {
  global $user_id;
  update_usermeta($user_id, 'symbiosis_default', $_POST['symbiosis_default']);
}

// update administration menu
function symbiosis_update_menu() {
  global $userdata, $submenu;
  if($userdata->user_level < 8) {
    if(isset($submenu['edit.php'][20])) {
      unset($submenu['edit.php'][20]);
    }
    if(isset($submenu['edit.php'][30])) {
      unset($submenu['edit.php'][30]);
    }
  }
}

// generate symbiosis global variable
function symbiosis_initialize() {
  global $wpdb;
  if(is_single()) {
    $id = $GLOBALS['post']->post_author;
  }
  elseif(is_category()) {
  	$cat_id = get_query_var('cat');
  	$cat_name = get_query_var('category_name');
    if(empty($cat_id) && !empty($cat_name)) {
	   	if(strpos($cat_name, '/') !== false) {
				$cat_name = explode('/', $cat_name);
				if(!empty($cat_name[count($cat_name) - 1])) {
          $cat_name = $cat_name[count($cat_name) - 1];
        }
				else {
          $cat_name = $cat_name[count($cat_name) - 2];
        }
  		}
  		$cat = get_term_by('slug', $cat_name, 'category');
  		$cat_id = $cat->cat_ID;
		}
		$id = $wpdb->get_var($wpdb->prepare(
      "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'symbiosis_category' AND meta_value = %d",
      symbiosis_category_root($cat_id)
    ));
  }
  elseif(is_author()) {
    $id = get_query_var('author');
    $name = get_query_var('author_name');
    if(empty($id) && !empty($name)) {
      $id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_nicename = %s", $name));
    }
  }
  else {
    $id = null;
  }
  if(!is_null($id)) {
    $name = get_userdata($id);
    $name = $name->display_name;
    $GLOBALS['symbiosis'] = compact('id', 'name');
  }
}

function symbiosis_widget_authors($args) {
  global $symbiosis;
  $options = symbiosis_widget($args, 'authors');
  $empty = !$options['empty'];
  $authors = wp_list_authors(array(
    'exclude_admin' => false,
    'hide_empty' => $empty,
    'echo' => false
  ));
  if(isset($symbiosis['id'])) {
    $authors = str_replace('">'. $symbiosis['name'], '" class="selected">'. $symbiosis['name'], $authors);
  }
  echo "<ul>$authors</ul>";
  symbiosis_widget($args);
}

function symbiosis_control_authors() {
	$options = $newoptions = get_option('symbiosis_authors');
	if($_POST['symbiosis-authors-submit']) {
		$newoptions['title'] = strip_tags(stripslashes($_POST['symbiosis-authors-title']));
		$newoptions['empty'] = isset($_POST['symbiosis-authors-empty']);
	}
	if($options != $newoptions) {
		$options = $newoptions;
		update_option('symbiosis_authors', $options);
	}
	$title_label = __('Title');
	$title = attribute_escape($options['title']);
	$empty_label = __('Show authors with no posts');
	$empty = $options['empty'] ? ' checked' : '';
	echo <<<HTML
  <p>
    <label for="symbiosis-authors-title">{$title_label}:
      <input type="text" class="widefat" id="symbiosis-authors-title" name="symbiosis-authors-title" value="{$title}" />
    </label>
  </p>
	<p>
		<label for="symbiosis-authors-empty">
			<input type="checkbox" class="checkbox" id="symbiosis-authors-empty" name="symbiosis-authors-empty"{$empty} />
			{$empty_label}
		</label>
	</p>
  <input type="hidden" name="symbiosis-authors-submit" id="symbiosis-authors-submit" value="1" />
HTML;
}

function symbiosis_widget_bookmarks($args) {
  global $symbiosis;
  if(isset($symbiosis['id'])) {
    $bookmarks = wp_list_bookmarks(array(
      'category' => get_usermeta($symbiosis['id'], 'symbiosis_link_category'),
      'echo' => false
    ));
    $bookmarks = trim(preg_replace('#<li[^>]*class="linkcat"[^>]*>.*?(<ul>.*?</ul>)\s*</li>#sim', '$1', $bookmarks));
    if(!empty($bookmarks)) {
      symbiosis_widget($args, 'bookmarks');
      echo $bookmarks;
      symbiosis_widget($args);
    }
  }
}

function symbiosis_control_bookmarks() {
	$options = $newoptions = get_option('symbiosis_bookmarks');
	if($_POST['symbiosis-bookmarks-submit']) {
		$newoptions['title'] = strip_tags(stripslashes($_POST['symbiosis-bookmarks-title']));
	}
	if($options != $newoptions) {
		$options = $newoptions;
		update_option('symbiosis_bookmarks', $options);
	}
	$title_label = __('Title');
	$title = attribute_escape($options['title']);
	echo <<<HTML
    <p>
      <label for="symbiosis-bookmarks-title">{$title_label}:
        <input type="text" class="widefat" id="symbiosis-bookmarks-title" name="symbiosis-bookmarks-title" value="{$title}" />
      </label>
    </p>
    <input type="hidden" name="symbiosis-bookmarks-submit" id="symbiosis-bookmarks-submit" value="1" />
HTML;
}

function symbiosis_widget_categories($args) {
  global $symbiosis;
  if(isset($symbiosis['id'])) {
    $home = get_usermeta($symbiosis['id'], 'symbiosis_category');
    $options = get_option('symbiosis_categories');
   	$empty = $options['empty'] ? '0' : '1';
    $categories =& get_categories("hide_empty={$empty}");
    $filter = array();
    $check = 0;
    foreach($categories as $category) {
      if(symbiosis_category_root($category->cat_ID) != $home) {
        $filter[] = $category->cat_ID;
      }
      elseif($category->cat_ID != $home) {
        $check++;
      }
    }
    if($check > 0) {
      $filter = implode(',', $filter);
      symbiosis_widget($args, 'categories');
    	$count = $options['count'] ? '1' : '0';
    	$hierarchical = $options['hierarchical'] ? '1' : '0';
    	$dropdown = $options['dropdown'] ? '1' : '0';
      $cat_args = "orderby=name&child_of={$home}&hide_empty={$empty}&show_count={$count}&hierarchical={$hierarchical}&exclude={$filter}";
    	if(isset($options['dropdown']) && $options['dropdown']) {
    	  $root = get_option('home');
    		wp_dropdown_categories($cat_args ."&show_option_none=". __('Select Category'));
    		echo <<<HTML
          <script type='text/javascript'>
          /* <![CDATA[ */
              var dropdown = document.getElementById("cat");
              function onCatChange() {
            		if ( dropdown.options[dropdown.selectedIndex].value > 0 ) {
            			location.href = "{$root}/?cat="+dropdown.options[dropdown.selectedIndex].value;
            		}
              }
              dropdown.onchange = onCatChange;
          /* ]]> */
          </script>
HTML;
    	}
      else {
    		$output = wp_list_categories($cat_args .'&echo=0&title_li=');
        echo "<ul>$output</ul>";
    	}
    	symbiosis_widget($args);
    }
  }
}

function symbiosis_control_categories() {
	$options = $newoptions = get_option('symbiosis_categories');
	if($_POST['symbiosis-categories-submit']) {
		$newoptions['title'] = strip_tags(stripslashes($_POST['symbiosis-categories-title']));
		$newoptions['count'] = isset($_POST['symbiosis-categories-count']);
		$newoptions['empty'] = isset($_POST['symbiosis-categories-empty']);
		$newoptions['hierarchical'] = isset($_POST['symbiosis-categories-hierarchical']);
		$newoptions['dropdown'] = isset($_POST['symbiosis-categories-dropdown']);
	}
	if($options != $newoptions) {
		$options = $newoptions;
		update_option('symbiosis_categories', $options);
	}
	$title_label = __('Title');
	$title = attribute_escape($options['title']);
	$count_label = __('Show post counts');
	$empty = $options['empty'] ? ' checked' : '';
	$empty_label = __('Show categories with no posts');
	$count = $options['count'] ? ' checked' : '';
	$hierarchical_label = __('Show hierarchy');
	$hierarchical = $options['hierarchical'] ? ' checked' : '';
	$dropdown_label = __('Show as dropdown');
	$dropdown = $options['dropdown'] ? ' checked' : '';
	echo <<<HTML
  <p>
    <label for="symbiosis-categories-title">{$title_label}:
      <input type="text" class="widefat" id="symbiosis-categories-title" name="symbiosis-categories-title" value="{$title}" />
    </label>
  </p>
	<p>
		<label for="symbiosis-categories-count">
			<input type="checkbox" class="checkbox" id="symbiosis-categories-count" name="symbiosis-categories-count"{$count} />
			{$count_label}
		</label>
	</p>
	<p>
		<label for="symbiosis-categories-empty">
			<input type="checkbox" class="checkbox" id="symbiosis-categories-empty" name="symbiosis-categories-empty"{$empty} />
			{$empty_label}
		</label>
	</p>
	<p>
		<label for="symbiosis-categories-hierarchical">
			<input type="checkbox" class="checkbox" id="symbiosis-categories-hierarchical" name="symbiosis-categories-hierarchical"{$hierarchical} />
			{$hierarchical_label}
		</label>
	</p>
	<p>
		<label for="symbiosis-categories-dropdown">
			<input type="checkbox" class="checkbox" id="symbiosis-categories-dropdown" name="symbiosis-categories-dropdown"{$dropdown} />
			{$dropdown_label}
		</label>
	</p>
  <input type="hidden" name="symbiosis-categories-submit" id="symbiosis-categories-submit" value="1" />
HTML;
}

function symbiosis_widget_posts($args) {
  global $symbiosis;
  $options = symbiosis_widget($args, 'posts');
  $return = '';
  if(isset($symbiosis['id'])) {
    $who = '&author='. $symbiosis['id'];
    $number = $options['author'];
  }
  else {
    $who = '';
    $number = $options['general'];
  }
  if(is_single()) {
    $id = is_attachment() ? $GLOBALS['post']->post_parent : $GLOBALS['post']->ID;
  }
  else {
    $id = null;
  }
	$r = new WP_Query("showposts=$number&what_to_show=posts$who&nopaging=0&post_status=publish");
	if($r->have_posts()) {
    while($r->have_posts()) {
      $r->the_post();
      $class = ($id == $GLOBALS['post']->ID) ? ' class="selected"' : '';
      $return .= '<li><a'. $class .' href="'. get_permalink() .'">'. get_the_title() .'</a></li>';
    }
		wp_reset_query();
	}
	echo "<ol>$return</ol>";
  symbiosis_widget($args);
}

function symbiosis_control_posts() {
	$options = $newoptions = get_option('symbiosis_posts');
	if($_POST['symbiosis-posts-submit']) {
		$newoptions['title'] = strip_tags(stripslashes($_POST['symbiosis-posts-title']));
		$newoptions['author'] = intval($_POST['symbiosis-posts-author']);
		$newoptions['general'] = intval($_POST['symbiosis-posts-general']);
		if($newoptions['author'] > 15) $newoptions['author'] = 15;
		if($newoptions['author'] < 5) $newoptions['author'] = 5;
		if($newoptions['general'] > 15) $newoptions['general'] = 15;
		if($newoptions['general'] < 5) $newoptions['general'] = 5;
	}
	if($options != $newoptions) {
		$options = $newoptions;
		update_option('symbiosis_posts', $options);
	}
	$title_label = __('Title');
	$title = attribute_escape($options['title']);
	$author_label = __('Number of posts from author');
	$author = attribute_escape($options['author']);
	$general_label = __('Number of posts from everyone');
	$general = attribute_escape($options['general']);
	$notice = __('from 5 to 15');
	echo <<<HTML
    <p>
      <label for="symbiosis-posts-title">{$title_label}:
        <input type="text" class="widefat" id="symbiosis-posts-title" name="symbiosis-posts-title" value="{$title}" />
      </label>
    </p>
		<p>
			<label for="symbiosis-posts-author">{$author_label}:
        <input style="width: 25px; text-align: center;" id="symbiosis-posts-author" name="symbiosis-posts-author" type="text" value="{$author}" />
      </label>
			<br />
			<small>({$notice})</small>
		</p>
		<p>
			<label for="symbiosis-posts-general">{$general_label}:
        <input style="width: 25px; text-align: center;" id="symbiosis-posts-general" name="symbiosis-posts-general" type="text" value="{$general}" />
      </label>
			<br />
			<small>({$notice})</small>
		</p>
    <input type="hidden" name="symbiosis-posts-submit" id="symbiosis-posts-submit" value="1" />
HTML;
}

function symbiosis_widgets() {
  symbiosis_register('authors',  __('Authors'), __('Symbiosis-aware list of blog authors'));
  symbiosis_register('bookmarks',  __('Bookmarks'), __('Symbiosis-aware list of bookmarks for current user'));
  symbiosis_register('categories',  __('Categories'), __('Symbiosis-aware list of categories for current user'));
  symbiosis_register('posts',  __('Posts'), __('Symbiosis-aware list of recent posts for current user'));
}

function symbiosis_register($name, $title, $description) {
  wp_register_sidebar_widget('symbiosis_'. $name, 'Sym '. $title, 'symbiosis_widget_'. $name, array(
    'classname' => 'symbiosis_'. $name,
    'description' => $description
  ));
  wp_register_widget_control('symbiosis_'. $name, 'Sym '. $title, 'symbiosis_control_'. $name);
}

function symbiosis_widget($args, $identifier = '') {
  extract($args);
  if(!empty($identifier)) {
    $options = get_option('symbiosis_'. $identifier);
    $title = empty($options['title']) ? __(ucfirst($identifier)) : $options['title'];
    echo $before_widget . $before_title . $title . $after_title;
    return $options;
  }
  else {
    echo $after_widget;
  }
}

function symbiosis_author_link($input, $user) {
  return get_category_link(get_usermeta($user, 'symbiosis_category'));
}

// activate and deactivate the plugin
register_activation_hook(__FILE__, 'symbiosis_activate');
register_deactivation_hook(__FILE__, 'symbiosis_deactivate');

// handle user profile options and updates
add_action('profile_personal_options', 'symbiosis_add_options');
add_action('personal_options_update', 'symbiosis_update_options');
add_action('user_register', 'symbiosis_update_user');
add_action('profile_update', 'symbiosis_update_user');
add_action('delete_user', 'symbiosis_delete_user');

// modify the user interface accordingly
add_action('admin_head', 'symbiosis_update_forms');
add_action('admin_menu', 'symbiosis_update_menu');

// go over added/edited data and make changes accordingly
add_action('add_link', 'symbiosis_link_save');
add_action('edit_link', 'symbiosis_link_save');
add_filter('category_save_pre', 'symbiosis_post_save');

// create a global variable for templates
add_action('wp_head', 'symbiosis_initialize');

// add plugin widgets and handle author links
add_action('init', 'symbiosis_widgets');
add_filter('author_link', 'symbiosis_author_link', 10, 2);

?>