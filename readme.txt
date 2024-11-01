=== Symbiosis ===
Contributors: klimbermann
Tags: bookmarks, categories, posts, users
Requires at least: 2.5
Tested up to: 2.5
Stable tag: trunk

A plugin to enable sensible content separation. Creates and manages separate
top-level categories for each user.

== Description ==

The goal of Symbiosis is to enable multiple users to express themselves side by
side, having some things in common (pages), but also some private space (categories,
posts and links). There is really no point in trying to explain - try, if you feel
like it!

When the plugin is activated, a category is created for each and every user in
the database and associated with the user. The name of the category is the same
as the display name for the user. If the display name is updated, so will be the
categories; if the user is deleted, the categories will be, too.

Users can add posts only to their home category and its children. If no categories
are selected, the home category will be used as a default. This behaviour can be
changed user-wise under personal profile options.

The category selection for links is removed and links will automatically be put
in the user's home category. Tags are disabled for clarity. Only administrators
can see and edit the full category tree.

For themes and plugins, a special global variable, `$symbiosis`, is provided.
It is an array with the ID and name of the current user, if a user-specific page
is accessed. The following pages are considered user-specific:

* An author archive page
* A page displaying posts from a user's home category or from its children
* A single post or attachment

Unlike posts and links, pages are not considered user-specific.

Some widgets using this property are included with the plugin, namely:

* Authors
* Bookmarks
* Categories
* Recent posts

== Installation ==

1. Upload `symbiosis.php` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Add some widgets to your theme.
4. Specify a default category on your profile and instruct others to do so too.