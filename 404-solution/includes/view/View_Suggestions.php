<?php


if (!defined('ABSPATH')) {
    exit;
}

class ABJ_404_Solution_View_Suggestions {

	/** @var self|null */
	private static $instance = null;
	/**
	 * Test seam: install or clear the cached singleton instance without
	 * private-field reflection. Pass null to reset between tests; pass a
	 * configured instance (or double) to install it (M105 singleton-reset seam).
	 *
	 * @param self|null $instance
	 * @return void
	 */
	public static function setInstance($instance) {
	    self::$instance = $instance;
	}


	/** @var ABJ_404_Solution_Functions */
	private $f;

	/**
	 * Constructor with dependency injection.
	 *
	 * @param ABJ_404_Solution_Functions|null $functions String utilities
	 */
	public function __construct($functions = null) {
		$this->f = $functions !== null ? $functions : abj_service('functions');
	}

	/** @return self */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new ABJ_404_Solution_View_Suggestions();
		}

		return self::$instance;
	}

	/**
     * @param array<string, mixed> $options
     * @return string
     */
    function getAdminOptionsPage404Suggestions($options) {
        $options = array_merge(array(
            'suggest_cats' => '0',
            'suggest_tags' => '0',
            'update_suggest_url' => '0',
            'suggest_max' => '5',
            'suggest_title' => '',
            'suggest_before' => '',
            'suggest_after' => '',
            'suggest_entrybefore' => '',
            'suggest_entryafter' => '',
            'suggest_noresults' => '',
        ), $options);
        
        // Suggested Alternatives Options
        $selectedSuggestCats = "";
        if ($options['suggest_cats'] == '1') {
            $selectedSuggestCats = " checked";
        }
        $selectedSuggestTags = "";
        if ($options['suggest_tags'] == '1') {
            $selectedSuggestTags = " checked";
        }
        $selectedSuggestURL = "";
        if ($options['update_suggest_url'] == '1') {
        	$selectedSuggestURL = " checked";
        }
        $selectedSuggestMinscoreEnabled = "";
        if (isset($options['suggest_minscore_enabled']) && $options['suggest_minscore_enabled'] == '1') {
        	$selectedSuggestMinscoreEnabled = " checked";
        }


        // read the html content.
        $html = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . "/html/viewSuggestions.html");
        $sugMax = $options['suggest_max'];
        $sugTitle = $options['suggest_title'];
        $sugBefore = $options['suggest_before'];
        $sugAfter = $options['suggest_after'];
        $sugEntryBefore = $options['suggest_entrybefore'];
        $sugEntryAfter = $options['suggest_entryafter'];
        $sugNoResults = $options['suggest_noresults'];
        // Constants and `{msgid}` translations run over the TEMPLATE; the admin-authored suggestion
        // markup below is bound last, in one pass, so a `{...}` inside it is never treated as a token.
        return $this->f->renderTemplate($html, array(
            '{SELECTED_SUGGEST_CATS}' => $selectedSuggestCats,
            '{SELECTED_SUGGEST_TAGS}' => $selectedSuggestTags,
            '{SELECTED_SUGGEST_MINSCORE_ENABLED}' => $selectedSuggestMinscoreEnabled,
            '{SELECTED_SUGGEST_URL}' => $selectedSuggestURL,
            '{SUGGEST_MAX_SUGGESTIONS}' => esc_attr(is_scalar($sugMax) ? (string)$sugMax : ''),
            '{SUGGEST_USER_TITLE}' => esc_attr(is_scalar($sugTitle) ? (string)$sugTitle : ''),
            '{SUGGEST_USER_BEFORE}' => esc_attr(is_scalar($sugBefore) ? (string)$sugBefore : ''),
            '{SUGGEST_USER_AFTER}' => esc_attr(is_scalar($sugAfter) ? (string)$sugAfter : ''),
            '{SUGGEST_USER_ENTRY_BEFORE}' => esc_attr(is_scalar($sugEntryBefore) ? (string)$sugEntryBefore : ''),
            '{SUGGEST_USER_ENTRY_AFTER}' => esc_attr(is_scalar($sugEntryAfter) ? (string)$sugEntryAfter : ''),
            '{SUGGEST_USER_NO_RESULTS}' => esc_attr(is_scalar($sugNoResults) ? (string)$sugNoResults : ''),
        ));
    }
    
}
