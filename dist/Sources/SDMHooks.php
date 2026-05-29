<?php
/**
 *	Logic for SMF Structured Data Markup hooks.
 *
 *	Copyright 2026 Shawn Bulen
 *
 *	SDM is free software: you can redistribute it and/or modify
 *	it under the terms of the GNU General Public License as published by
 *	the Free Software Foundation, either version 3 of the License, or
 *	(at your option) any later version.
 *
 *	This software is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU General Public License for more details.
 *
 *	You should have received a copy of the GNU General Public License
 *	along with this software.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

// If we are outside SMF throw an error.
if (!defined('SMF')) {
    die('Hacking attempt...');
}

/**
 *
 * Hook function - Use this hook early in Display.php to initialize our structured data.
 *
 * Hook: integrate_display_message_list
 *
 * @param array $messages
 * @param array $posters
 *
 * @return null
 *
 */
function sdm_display_message_list(&$messages, &$posters)
{
	global $context;

	$context['structured_data_stub'] = array();
	$context['structured_data_post'] = array();
	$context['structured_data_comments'] = array();
}

/**
 *
 * Hook function - get message info.  Called once per message.
 *
 * Main post & comments are tracked separately.  They will be assembled at the end of the page when the json is built.
 * This will allow us to properly handle any sort order, e.g., if view_newest_first is set,
 * we can still put the comments under the post, even when not delivered in that order.
 *
 * So...
 * For the main post, we want a DiscussionForumPosting with a property of mainEntityOfPage, with full attributes for the post.
 * For all the replies on that page, we want them nested under the post.
 * For all the replies on pages 2+, we want a DiscussionForumPosting stub, with minimal attribution & pagination info, with comments nested underneath.
 *
 * Hook: integrate_prepare_display_context
 *
 * @param array $output
 * @param array $message
 * @param array $counter
 *
 * @return null
 *
 */
function sdm_prepare_display_context(&$output, &$message, $counter)
{
	global $context, $user_info, $scripturl, $board, $topic, $options;

	// Only do all this for guests, don't waste cycles for logged-in users.
	// Also just... minimize info exposed via structured data...
	if (!$user_info['is_guest'])
		return;

	// Only do approved messages.  This is normally filtered by the template, so we have to do it ourselves here...
	if ($message['approved'] != '1')
		return;

	// Got the post...
	if ($context['topic_first_message'] == $output['id'])
	{
		$context['structured_data_post']['@context'] = 'https://schema.org';
		$context['structured_data_post']['@type'] = 'DiscussionForumPosting';
		$context['structured_data_post']['mainEntityOfPage'] = $context['canonical_url'];
		$context['structured_data_post']['headline'] = $output['subject'];
		$context['structured_data_post']['text'] = $output['body'];
		$context['structured_data_post']['url'] = $output['href'];
		$context['structured_data_post']['datePublished'] = date('c', $output['timestamp']);
		if ($context['page_info']['num_pages'] > 1)
			$context['structured_data_post']['pagination'] = $context['page_info']['current_page'];
		if ($context['num_replies'] > 0)
			$context['structured_data_post']['commentCount'] = $context['num_replies'];
		if (!empty($output['modified']['timestamp']))
			$context['structured_data_post']['dateModified'] = date('c', $output['modified']['timestamp']);

		// isPartOf...  For posts, build canonical for board...
		if (!empty($board))
			$context['structured_data_post']['isPartOf'] = $scripturl . '?board=' . trim($board) . '.0';

		if (($context['num_views'] != 0) || (!empty($message['likes']) && ($message['likes'] > 0)))
		{
			// Views block
			if ($context['num_views'] != 0)
			{
				$temp_v = array();
				$temp_v['@type'] = 'InteractionCounter';
				$temp_v['interactionType'] = 'https://schema.org/ViewAction';
				$temp_v['userInteractionCount'] = $context['topicinfo']['num_views'];
				$context['structured_data_post']['interactionStatistic'][] = $temp_v;
			}
			// Likes block
			if (!empty($message['likes']) && $message['likes'] > 0)
			{
				$temp_l = array();
				$temp_l['@type'] = 'InteractionCounter';
				$temp_l['interactionType'] = 'https://schema.org/LikeAction';
				$temp_l['userInteractionCount'] = $message['likes'];
				$context['structured_data_post']['interactionStatistic'][] = $temp_l;
			}
		}

		// Author block
		$context['structured_data_post']['author']['@type'] = 'Person';
		$context['structured_data_post']['author']['name'] = $output['member']['name'];
		if (!empty($output['member']['avatar']['url']))
		{
			$context['structured_data_post']['author']['image'] = $output['member']['avatar']['url'];
		}
		// Show profile if it's authorized for guests...
		if (!empty($output['member']['can_view_profile']))
		{
			$context['structured_data_post']['author']['url'] = $output['member']['href'];
		}

		// Attachments - if images, & approved, & visible, share 'em...
		// They're not in $output if not approved...
		$temp_att = array();
		if (!empty($output['attachment']))
		{
			foreach ($output['attachment'] AS $att_info)
			{
				if (($att_info['is_image'] == true) && ($att_info['is_approved'] == 1))
					$temp_att[] = $att_info['href'];
			}
			if (!empty($temp_att))
				$context['structured_data_post']['image'] = $temp_att;
		}
	}
	// Got replies/comments...
	else
	{
		// Need the canonical URL for the topic page that has the original post on it.
		// Usually this is the first page, but might be the last, if view_newest_first is set.
		if (empty($first_post_url))
		{
			if (empty($options['view_newest_first']) || ($context['page_info']['current_page'] == $context['page_info']['num_pages']))
				$first_post_start = 0;
			else
				$first_post_start = (((int) $context['page_info']['num_pages']) - 1) * (int) $context['messages_per_page'];
			if ($first_post_start < 0)
				$first_post_start = 0;
			$first_post_url = $scripturl . '?topic=' . trim($topic) . '.' . $first_post_start;
		}

		// Save off stub/pagination info, just in case post not on this page.  We hang page 2+ comments off of this.
		// Note the spec says NEVER to include content not on your actual page, however, these comment pages
		// error out without the author & datePublished of the original post...  Added here to address the error.
		if (empty($context['structured_data_stub']))
		{
			$context['structured_data_stub']['@context'] = 'https://schema.org';
			$context['structured_data_stub']['@type'] = 'DiscussionForumPosting';
			$context['structured_data_stub']['url'] = $first_post_url;
			$context['structured_data_stub']['headline'] = $output['subject'];
			$context['structured_data_stub']['pagination'] = $context['page_info']['current_page'];
			$context['structured_data_stub']['datePublished'] = date('c', $context['topic_started_timestamp']);
			$context['structured_data_stub']['author']['@type'] = "Person";
			$context['structured_data_stub']['author']['name'] = $context['topic_poster_name'];
			// isPartOf...  For posts, build canonical for board...
			if (!empty($board))
				$context['structured_data_stub']['isPartOf'] = $scripturl . '?board=' . trim($board) . '.0';
		}

		// Now to save off the current comment/reply info...
		$temp = array();
		$temp['@type'] = 'Comment';
		$temp['text'] = $output['body'];
		$temp['url'] = $output['href'];
		$temp['datePublished'] = date('c', $output['timestamp']);

		if (!empty($output['modified']['timestamp']))
			$temp['dateModified'] = date('c', $output['modified']['timestamp']);

		// Likes block
		if (!empty($message['likes']) && $message['likes'] > 0)
		{
			$temp['interactionStatistic']['@type'] = 'InteractionCounter';
			$temp['interactionStatistic']['interactionType'] = 'https://schema.org/LikeAction';
			$temp['interactionStatistic']['userInteractionCount'] = $message['likes'];
		}

		// Author block
		$temp['author']['@type'] = "Person";
		$temp['author']['name'] = $output['member']['name'];
		if (!empty($output['member']['avatar']['url']))
		{
			$temp['author']['image'] = $output['member']['avatar']['url'];
		}
		// Show profile if it's authorized for guests...
		if (!empty($output['member']['can_view_profile']))
		{
			$temp['author']['url'] = $output['member']['href'];
		}

		// Attachments - if images, & approved, & visible, share 'em...
		// Note: They're not in $output if not approved...
		$temp_att = array();
		if (!empty($output['attachment']))
		{
			foreach ($output['attachment'] AS $att_info)
			{
				if (($att_info['is_image'] == true) && ($att_info['is_approved'] == 1))
					$temp_att[] = $att_info['href'];
			}
			if (!empty($temp_att))
				$temp['image'] = $temp_att;
		}
		$context['structured_data_comments'][] = $temp;
	}
}

/**
 *
 * Hook function - Spit out structured data here, at the bottom of the template, with the deferred js,
 * after page is completely built & populated.
 *
 * Two types are generated:
 *  - DiscussionForumPosting for posts & replies (content built earlier)
 *  - Breadcrumbs for every page (built here)
 *
 * Hook: integrate_pre_javascript_output
 *
 * @param array $deferred
 *
 * @return null
 *
 */
function sdm_pre_javascript_output(&$deferred)
{
	global $context, $user_info;

	// Only do all this for guests, users don't need it, only search engines...
	// Also just... minimize info exposed via structured data...
	if (!$user_info['is_guest'])
		return;

	// We must wait until doing the deferred js at the bottom of the page, after page has been built
	if (empty($deferred))
		return;

	// Build breadcrumbs here from linktree
	$breadcrumbs = array(
		'@context' => 'https://schema.org',
		'@type' => 'BreadcrumbList'
	);
	$position = 1;
	$bcitems = array();
	foreach ($context['linktree'] AS $linkinfo)
	{
		$temp = array();
		$temp['@type'] = 'ListItem';
		$temp['position'] = $position;
		$temp['name'] = $linkinfo['name'];
		$temp['item'] = $linkinfo['url'];
		$bcitems[] = $temp;
		$position++;
	}
	// Google wants you to drop last breadcrumb since you're already on that page
	array_pop($bcitems);

	// Might be empty now, e.g., on homepage
	if (!empty($bcitems))
	{
		$breadcrumbs['itemListElement'] = $bcitems;

		echo '
<script type="application/ld+json">
', json_encode($breadcrumbs, JSON_OBJECT_AS_ARRAY | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), '
</script>';
	}

	// Bail if no posts or replies/comments, i.e., any page other than topics...
	if (empty($context['structured_data_post']) && empty($context['structured_data_comments']))
		return;

	// Cobble the data we have together...
	if (!empty($context['structured_data_post']))
	{
		// Page with original post, pg 1 of topic = post + maybe comments
		$structured_data = $context['structured_data_post'];
		if (!empty($context['structured_data_comments']))
			$structured_data['comment'] = $context['structured_data_comments'];
	}
	else
	{
		// Page of replies/comments only, pg 2+ of topic = stub + comments
		$structured_data = $context['structured_data_stub'];
		$structured_data['comment'] = $context['structured_data_comments'];
	}

	echo '
<script type="application/ld+json">
', json_encode($structured_data, JSON_OBJECT_AS_ARRAY | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), '
</script>';

}