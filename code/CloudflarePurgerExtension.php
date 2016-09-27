<?php

/**
 * This Extension can be applied to DataObjects that are accessible via a URL or that are associated to an object is
 * reachable via a URL.
 */
class CloudflarePurgerExtension extends DataExtension
{

    /**
     * Bit mask for purging the draft site.
     */
    const PURGE_DRAFT = 0b01;

    /**
     * Bit mask for purging the live version of the site.
     */
    const PURGE_LIVE = 0b10;

    /**
     * Bit mask for purging the both the draft and live version of the site.
     */
    const PURGE_ALL = 0b11;

    /**
     * List of fields that are likely to invalidate the site navigation and to require a full website purge.
     * @var string[]
     */
    protected $navChangeFields = [
        'ShowInMenus',
        'Sort',
        'ParentID',
        'URLSegment',
        'MenuTitle'
    ];

    /**
     * This hook will be called on objects that have the `Versioned` extension applied on the them before a new version
     * is published.
     */
    public function onBeforeVersionedPublish()
    {
        if ($this->detectVersionedNavChange()) {
            CloudflarePurger::purgeEverything();
        } else {
            $this->purge(self::PURGE_LIVE);
        }
    }

    /**
     * This hook will be called after an object is saved. If the object doesn't implement the `Versioned` Extension, it
     * will try to purge URL from Cloudflare.
     */
    public function onAfterWrite()
    {
        if ($this->owner->hasExtension('Versioned')) {
            $this->purge(self::PURGE_DRAFT);
        } else {
            if ($this->detectNavChange()) {
                CloudflarePurger::purgeEverything();
            } else {
                $this->purge(self::PURGE_LIVE);
            }
        }
    }

    /**
     * Purge the object after its been deleted.
     */
    public function onAfterDelete()
    {
        if (isset($this->owner->ShowInMenu) && $this->owner->ShowInMenu) {
            CloudflarePurger::purgeEverything();
        } elseif ($this->owner->hasExtension('Versioned')) {
            $this->purge(self::PURGE_ALL);
        } else {
            $this->purge(self::PURGE_LIVE);
        }
    }

    /**
     * Detect alteration to a Versionned Data object that are likely to invalidate the site navigation and require a full purge.
     * @return boolean
     */
    protected function detectVersionedNavChange()
    {
        $live = Versioned::get_by_stage($this->owner->getClassName(), 'Live')->byID($this->owner->ID);
        $stage = Versioned::get_by_stage($this->owner->getClassName(), 'Stage')->byID($this->owner->ID);

        if (!$live || !$stage) {
            return true;
        }

        $diff = new DataDifferencer($live, $stage);

        $changedFields = $diff->changedFieldNames();

        return !empty(array_intersect($changedFields, $this->navChangeFields));

    }

    /**
     * Detect alteration that will invalidate the site navigation and require a complete purge of the site.
     * @return boolean
     */
    protected function detectNavChange()
    {
        foreach ($this->navChangeFields as $fieldName) {
            if ($this->owner->isChanged($fieldName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try to purge URLs from Cloudflare for this DataObject.
     *
     * @param int $purgeMask What we should purge
     */
    protected function purge($purgeMask = self::PURGE_LIVE)
    {
        // Get the links to purge
        $links = $this->getPurgeLinks();

        // If we have URLs, proceed with purge.
        if ($links) {
            try {
                if ($purgeMask & self::PURGE_LIVE) {
                    CloudflarePurger::purge($links);
                }

                if ($purgeMask & self::PURGE_DRAFT) {
                    $this->convertToStageUrl($links);
                    CloudflarePurger::purge($links);
                }
            } catch (Exception $ex) {
                SS_Log::log($ex->getMessage(), SS_Log::NOTICE);
            }
        }
    }

    /**
     * Add a the stage parameter at the end of the URLs to purge. Will work even if there's an existing URL parameter
     * on the links.
     * @param  string[] $links
     * @see http://stackoverflow.com/questions/5809774/manipulate-a-url-string-by-adding-get-parameters
     */
    protected function convertToStageUrl(&$links)
    {
        foreach ($links as &$link) {
            // Parse the URL into it's sub commponent
            $parts = parse_url($link);

            if (isset($parts['query'])) {
                parse_str($parts['query'], $params);
            } else {
                $params = [];
            }

            // Set the stage parameter
            $params['stage'] = 'Stage';

            // Rebuild the query part of the URL
            $parts['query'] = http_build_query($params);

            // Reasseble the link
            $link = $parts['path'] . '?' . $parts['query'];
        }
    }

    /**
     * Build a list of URLs to purge.
     *
     * The DataObject can implement a `CloudflarePurgeLinks` method to define what URLs should be purge. That Url can
     * return a simple string or an array of string. The Url return should be relative to the site root.
     *
     * If the parent DataObject doesn't have a `CloudflarePurgeLinks` method, we'll try to access the `Link()` method
     * instead.
     *
     * @return string[]
     */
    protected function getPurgeLinks()
    {

        $links = false;

        // Get links from parent.
        if ($this->owner->hasMethod('CloudflarePurgeLinks')) {
            $links = $this->owner->CloudflarePurgeLinks();

        } elseif ($this->owner->hasMethod('Link')) {
            $links = $this->owner->Link();
        }

        // Make sure we always return an array.
        if (!$links) {
            return [];
        } elseif (is_array($links)) {
            return $links;
        } else {
            return [$links];
        }
    }

}
