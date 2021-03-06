<?php
/**
 *
 * @package phpBB Extension - RH Topic Tags
 * @copyright (c) 2014 Robet Heim
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace robertheim\topictags\controller;

/**
 * @ignore
 */
use phpbb\json_response;

class main
{

	protected $config;

	protected $template;

	protected $helper;

	protected $request;

	protected $user;

	protected $auth;

	/** @var \phpbb\event\dispatcher */
	protected $phpbb_dispatcher;

	/** @var \Symfony\Component\DependencyInjection\ContainerInterface */
	protected $phpbb_container;

	protected $php_ext;

	protected $phpbb_root_path;

	protected $tags_manager;

	protected $tagcloud_manager;

	/**
	 * Constructor
	 */
	public function __construct(
						\phpbb\config\config $config,
						\phpbb\template\template $template,
						\phpbb\controller\helper $helper,
						\phpbb\request\request $request,
						\phpbb\user $user,
						\phpbb\auth\auth $auth,
						\phpbb\event\dispatcher $phpbb_dispatcher,
						\Symfony\Component\DependencyInjection\ContainerInterface $phpbb_container,
						$php_ext,
						$phpbb_root_path,
						\robertheim\topictags\service\tags_manager $tags_manager,
						\robertheim\topictags\service\tagcloud_manager $tagcloud_manager
	)
	{
		$this->config			= $config;
		$this->template			= $template;
		$this->helper			= $helper;
		$this->request			= $request;
		$this->user				= $user;
		$this->auth				= $auth;
		$this->phpbb_dispatcher	= $phpbb_dispatcher;
		$this->phpbb_container	= $phpbb_container;
		$this->php_ext			= $php_ext;
		$this->phpbb_root_path	= $phpbb_root_path;
		$this->tags_manager		= $tags_manager;
		$this->tagcloud_manager	= $tagcloud_manager;
	}

	/**
	 * Demo controller for route /tags
	 *
	 * @return \Symfony\Component\HttpFoundation\Response A Symfony Response object
	 */
	public function show()
	{
		$this->tagcloud_manager->assign_tagcloud_to_template(-1);
		return $this->helper->render('tags.html', 'Tags');
	}

	/**
	 * shows a list of topics that have the given $tags assigned
	 *
	 * @param $tags tags seperated by comma (",")
	 * @param $mode the mode indicates whether all tags (AND, default) or any tag (OR) should be assigned to the resulting topics
	 * @param casesensitive wether to search case-sensitive (true) or -insensitive (false, default)
	 */
	public function show_tag($tags, $mode, $casesensitive)
	{
		$phpbb_container = $this->phpbb_container;
		$request = $this->request;
		$user = $this->user;
		$config = $this->config;
		$auth = $this->auth;
		$phpbb_dispatcher = $this->phpbb_dispatcher;

		// validate mode
		// default == AND
		$mode = ($mode == 'OR' ? 'OR' : 'AND');

		$tags = explode(',', urldecode($tags));
		// remove possible duplicates
		$tags = array_unique($tags);
		$all_tags = $this->tags_manager->split_valid_tags($tags);

		if (sizeof($all_tags['invalid']) > 0)
		{
			$this->template->assign_var('RH_TOPICTAGS_SEARCH_IGNORED_TAGS',
				$this->user->lang('RH_TOPICTAGS_SEARCH_IGNORED_TAGS', join(', ', $all_tags['invalid']))
			);
		}

		$tags = $all_tags['valid'];
		$tags_string = join(', ', $tags);
		$this->template->assign_var('RH_TOPICTAGS_SEARCH_HEADER',
			$user->lang('RH_TOPICTAGS_SEARCH_HEADER_' . $mode, $tags_string)
		);
		if (empty($tags))
		{
			// no valid tags
			return $this->helper->render('show_tag.html', 'Tag-'.$user->lang('SEARCH'));
		}

		$pagination		= $phpbb_container->get('pagination');

		$start			= $request->variable('start', 0);
		$limit			= $config['topics_per_page'];

		$topics_count	= $this->tags_manager->count_topics_by_tags($tags, $mode, $casesensitive);
		$start			= $pagination->validate_start($start, $limit, $topics_count);

		$topics			= $this->tags_manager->get_topics_by_tags($tags, $start, $limit, $mode, $casesensitive);

		$base_url		= $this->helper->route('robertheim_topictags_show_tag_controller', array(
								'tags'	=> urlencode($tags_string),
							));
		$base_url		= append_sid($base_url);

		$pagination->generate_template_pagination($base_url, 'pagination', 'start', $topics_count, $limit, $start);

		$user->add_lang('viewforum');
		$this->template->assign_var('TOTAL_TOPICS', $user->lang('VIEW_FORUM_TOPICS', $topics_count));

		if (sizeof($topics) <= 0)
		{
			$this->template->assign_var('NO_TOPICS_FOR_TAG', $user->lang('RH_TOPICTAGS_NO_TOPICS_FOR_TAG_'.$mode,
				$tags_string));
		}
		else
		{
			$this->template->assign_vars(array(
				'NEWEST_POST_IMG'			=> $user->img('icon_topic_newest', 'VIEW_NEWEST_POST'),
				'LAST_POST_IMG'				=> $user->img('icon_topic_latest', 'VIEW_LATEST_POST'),
				'REPORTED_IMG'				=> $user->img('icon_topic_reported', 'TOPIC_REPORTED'),
				'UNAPPROVED_IMG'			=> $user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
				'DELETED_IMG'				=> $user->img('icon_topic_deleted', 'TOPIC_DELETED'),
				'POLL_IMG'					=> $user->img('icon_topic_poll', 'TOPIC_POLL'),
				'S_TOPIC_ICONS'				=> true,
			));

			$phpbb_content_visibility = $phpbb_container->get('content.visibility');
			include_once($this->phpbb_root_path . 'includes/functions_display.' . $this->php_ext);

			foreach ($topics as $topic)
			{
				$topic_id = $topic['topic_id'];
				$row = $topic;
				$s_type_switch = 0;

				$topic_forum_id = ($row['forum_id']) ? (int) $row['forum_id'] : $forum_id;

				// This will allow the style designer to output a different header
				// or even separate the list of announcements from sticky and normal topics
				$s_type_switch_test = ($row['topic_type'] == POST_ANNOUNCE || $row['topic_type'] == POST_GLOBAL) ? 1 : 0;

				// Replies
				$replies = $phpbb_content_visibility->get_count('topic_posts', $row, $topic_forum_id) - 1;

				if ($row['topic_status'] == ITEM_MOVED)
				{
					$topic_id = $row['topic_moved_id'];
					$unread_topic = false;
				}
				else
				{
					$unread_topic = (isset($topic_tracking_info[$topic_id]) && $row['topic_last_post_time'] > $topic_tracking_info[$topic_id]) ? true : false;
				}

				// Get folder img, topic status/type related information
				$folder_img = $folder_alt = $topic_type = '';
				topic_status($row, $replies, $unread_topic, $folder_img, $folder_alt, $topic_type);

				// Generate all the URIs ...
				$view_topic_url_params = 'f=' . $row['forum_id'] . '&amp;t=' . $topic_id;
				$view_topic_url = append_sid("{$this->phpbb_root_path}viewtopic.{$this->php_ext}", $view_topic_url_params);

				$topic_unapproved = (($row['topic_visibility'] == ITEM_UNAPPROVED || $row['topic_visibility'] == ITEM_REAPPROVE) && $auth->acl_get('m_approve', $row['forum_id']));
				$posts_unapproved = ($row['topic_visibility'] == ITEM_APPROVED && $row['topic_posts_unapproved'] && $auth->acl_get('m_approve', $row['forum_id']));
				$topic_deleted = $row['topic_visibility'] == ITEM_DELETED;

				$u_mcp_queue = ($topic_unapproved || $posts_unapproved) ? append_sid("{$this->phpbb_root_path}mcp.{$this->php_ext}", 'i=queue&amp;mode=' . (($topic_unapproved) ? 'approve_details' : 'unapproved_posts') . "&amp;t=$topic_id", true, $user->session_id) : '';
				$u_mcp_queue = (!$u_mcp_queue && $topic_deleted) ? append_sid("{$this->phpbb_root_path}mcp.{$this->php_ext}", 'i=queue&amp;mode=deleted_topics&amp;t=' . $topic_id, true, $user->session_id) : $u_mcp_queue;

				// Send vars to template
				$topic_row = array(
					'FORUM_ID'					=> $row['forum_id'],
					'TOPIC_ID'					=> $topic_id,
					'TOPIC_AUTHOR'				=> get_username_string('username', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'TOPIC_AUTHOR_COLOUR'		=> get_username_string('colour', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'TOPIC_AUTHOR_FULL'			=> get_username_string('full', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'FIRST_POST_TIME'			=> $user->format_date($row['topic_time']),
					'LAST_POST_SUBJECT'			=> censor_text($row['topic_last_post_subject']),
					'LAST_POST_TIME'			=> $user->format_date($row['topic_last_post_time']),
					'LAST_VIEW_TIME'			=> $user->format_date($row['topic_last_view_time']),
					'LAST_POST_AUTHOR'			=> get_username_string('username', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
					'LAST_POST_AUTHOR_COLOUR'	=> get_username_string('colour', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
					'LAST_POST_AUTHOR_FULL'		=> get_username_string('full', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),

					'REPLIES'			=> $replies,
					'VIEWS'				=> $row['topic_views'],
					'TOPIC_TITLE'		=> censor_text($row['topic_title']),
					'TOPIC_TYPE'		=> $topic_type,
					'FORUM_NAME'		=> (isset($row['forum_name'])) ? $row['forum_name'] : '',

					'TOPIC_IMG_STYLE'		=> $folder_img,
					'TOPIC_FOLDER_IMG'		=> $user->img($folder_img, $folder_alt),
					'TOPIC_FOLDER_IMG_ALT'	=> $user->lang[$folder_alt],

					'TOPIC_ICON_IMG'		=> (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['img'] : '',
					'TOPIC_ICON_IMG_WIDTH'	=> (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['width'] : '',
					'TOPIC_ICON_IMG_HEIGHT'	=> (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['height'] : '',
					'ATTACH_ICON_IMG'		=> ($auth->acl_get('u_download') && $auth->acl_get('f_download', $row['forum_id']) && $row['topic_attachment']) ? $user->img('icon_topic_attach', $user->lang['TOTAL_ATTACHMENTS']) : '',
					'UNAPPROVED_IMG'		=> ($topic_unapproved || $posts_unapproved) ? $user->img('icon_topic_unapproved', ($topic_unapproved) ? 'TOPIC_UNAPPROVED' : 'POSTS_UNAPPROVED') : '',

					'S_TOPIC_TYPE'			=> $row['topic_type'],
					'S_USER_POSTED'			=> (isset($row['topic_posted']) && $row['topic_posted']) ? true : false,
					'S_UNREAD_TOPIC'		=> $unread_topic,
					'S_TOPIC_REPORTED'		=> (!empty($row['topic_reported']) && $auth->acl_get('m_report', $row['forum_id'])) ? true : false,
					'S_TOPIC_UNAPPROVED'	=> $topic_unapproved,
					'S_POSTS_UNAPPROVED'	=> $posts_unapproved,
					'S_TOPIC_DELETED'		=> $topic_deleted,
					'S_HAS_POLL'			=> ($row['poll_start']) ? true : false,
					'S_POST_ANNOUNCE'		=> ($row['topic_type'] == POST_ANNOUNCE) ? true : false,
					'S_POST_GLOBAL'			=> ($row['topic_type'] == POST_GLOBAL) ? true : false,
					'S_POST_STICKY'			=> ($row['topic_type'] == POST_STICKY) ? true : false,
					'S_TOPIC_LOCKED'		=> ($row['topic_status'] == ITEM_LOCKED) ? true : false,
					'S_TOPIC_MOVED'			=> ($row['topic_status'] == ITEM_MOVED) ? true : false,

					'U_NEWEST_POST'			=> append_sid("{$this->phpbb_root_path}viewtopic.{$this->php_ext}", $view_topic_url_params . '&amp;view=unread') . '#unread',
					'U_LAST_POST'			=> append_sid("{$this->phpbb_root_path}viewtopic.{$this->php_ext}", $view_topic_url_params . '&amp;p=' . $row['topic_last_post_id']) . '#p' . $row['topic_last_post_id'],
					'U_LAST_POST_AUTHOR'	=> get_username_string('profile', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
					'U_TOPIC_AUTHOR'		=> get_username_string('profile', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
					'U_VIEW_TOPIC'			=> $view_topic_url,
					'U_MCP_REPORT'			=> append_sid("{$this->phpbb_root_path}mcp.{$this->php_ext}", 'i=reports&amp;mode=reports&amp;f=' . $row['forum_id'] . '&amp;t=' . $topic_id, true, $user->session_id),
					'U_MCP_QUEUE'			=> $u_mcp_queue,

					'S_TOPIC_TYPE_SWITCH'	=> ($s_type_switch == $s_type_switch_test) ? -1 : $s_type_switch_test,
				);

				/**
				* Modify the topic data before it is assigned to the template
				*
				* @event robertheim.topictags.viewforum_modify_topicrow
				* @var	array	row			Array with topic data
				* @var	array	topic_row	Template array with topic data
				* @since 0.0.13-b1
				*/
				$vars = array('row', 'topic_row');
				extract($phpbb_dispatcher->trigger_event('robertheim.topictags.viewforum_modify_topicrow', compact($vars)));

				$this->template->assign_block_vars('topicrow', $topic_row);

				// mini pagination of posts in topic-rowss
				$pagination->generate_template_pagination($view_topic_url, 'topicrow.pagination', 'start', $replies + 1, $config['posts_per_page'], 1, true, true);
			} // foreach
		} // else
		return $this->helper->render('show_tag.html', 'Tag-'.$user->lang('SEARCH'));
	}

	/**
	 * Gets suggestions for tags based on a ajax request, route: /tags/suggest
	 *
	 * @param php://input raw post data must contain a json-encoded object of this structure: {"query":"...", "exclude":["...", "...", ...]}
	 */
	public function suggest_tags()
	{
		if ($this->request->is_ajax())
		{
			$data = json_decode(file_get_contents('php://input'), true);
			$query = $data['query'];
			$exclude = $data['exclude'];
			$tags = $this->tags_manager->get_tag_suggestions($query, $exclude, 5);
			$json_response = new json_response();
			$json_response->send($tags);
		}
		// fake a 404
		return $this->helper->error('No route found for "' . $this->helper->get_current_url() . '"' , 404);
	}
}
