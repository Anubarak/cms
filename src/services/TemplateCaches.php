<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\tasks\DeleteStaleTemplateCaches;
use DateTime;
use yii\base\Component;
use yii\base\Event;

/**
 * Class TemplateCaches service.
 *
 * An instance of the TemplateCaches service is globally accessible in Craft via [[Application::templateCaches `Craft::$app->getTemplateCaches()`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class TemplateCaches extends Component
{
    // Properties
    // =========================================================================

    /**
     * The table that template caches are stored in.
     *
     * @var string
     */
    private static $_templateCachesTable = '{{%templatecaches}}';

    /**
     * The table that template cache-element relations are stored in.
     *
     * @var string
     */
    private static $_templateCacheElementsTable = '{{%templatecacheelements}}';

    /**
     * The table that queries used within template caches are stored in.
     *
     * @var string
     */
    private static $_templateCacheQueriesTable = '{{%templatecachequeries}}';

    /**
     * The duration (in seconds) between the times when Craft will delete any expired template caches.
     *
     * @var int
     */
    private static $_lastCleanupDateCacheDuration = 86400;

    /**
     * The current request's path, as it will be stored in the templatecaches table.
     *
     * @var string
     */
    private $_path;

    /**
     * A list of element queries that were executed within the existing caches.
     *
     * @var array
     */
    private $_cachedQueries;

    /**
     * A list of element IDs that are active within the existing caches.
     *
     * @var array
     */
    private $_cacheElementIds;

    /**
     * Whether expired caches have already been deleted in this request.
     *
     * @var bool
     */
    private $_deletedExpiredCaches = false;

    /**
     * Whether all caches have been deleted in this request.
     *
     * @var bool
     */
    private $_deletedAllCaches = false;

    /**
     * Whether all caches have been deleted, on a per-element type basis, in this request.
     *
     * @var bool
     */
    private $_deletedCachesByElementType;

    // Public Methods
    // =========================================================================

    /**
     * Returns a cached template by its key.
     *
     * @param string  $key    The template cache key
     * @param boolean $global Whether the cache would have been stored globally.
     *
     * @return string|null
     */
    public function getTemplateCache($key, $global)
    {
        // Make sure template caching is enabled
        if ($this->_isTemplateCachingEnabled() === false) {
            return null;
        }

        // Don't return anything if it's not a global request and the path > 255 characters.
        if (!$global && strlen($this->_getPath()) > 255) {
            return null;
        }

        // Take the opportunity to delete any expired caches
        $this->deleteExpiredCachesIfOverdue();

        $query = (new Query())
            ->select(['body'])
            ->from([self::$_templateCachesTable])
            ->where([
                'and',
                [
                    'cacheKey' => $key,
                    'siteId' => Craft::$app->getSites()->currentSite->id
                ],
                ['>', 'expiryDate', Db::prepareDateForDb(new \DateTime())],
            ]);

        if (!$global) {
            $query->andWhere([
                'path' => $this->_getPath()
            ]);
        }

        $cachedBody = $query->scalar();

        if ($cachedBody === false) {
            return null;
        }

        return $cachedBody;
    }

    /**
     * Starts a new template cache.
     *
     * @param string $key The template cache key.
     *
     * @return void
     */
    public function startTemplateCache($key)
    {
        // Make sure template caching is enabled
        if ($this->_isTemplateCachingEnabled() === false) {
            return;
        }

        // Is this the first time we've started caching?
        if ($this->_cachedQueries === null) {
            Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_PREPARE, [
                $this,
                'includeElementQueryInTemplateCaches'
            ]);
        }

        if (Craft::$app->getConfig()->get('cacheElementQueries')) {
            $this->_cachedQueries[$key] = [];
        }

        $this->_cacheElementIds[$key] = [];
    }

    /**
     * Includes an element criteria in any active caches.
     *
     * @param Event $event The 'afterPrepare' element query event
     *
     * @return void
     */
    public function includeElementQueryInTemplateCaches(Event $event)
    {
        // Make sure template caching is enabled
        if ($this->_isTemplateCachingEnabled() === false) {
            return;
        }

        if (!empty($this->_cachedQueries)) {
            /** @var ElementQuery $elementQuery */
            $elementQuery = $event->sender;
            $query = $elementQuery->query;
            $subQuery = $elementQuery->subQuery;
            $elementQuery->query = null;
            $elementQuery->subQuery = null;
            // We need to base64-encode the string so db\Connection::quoteSql() doesn't tweak any of the table/columns names
            $serialized = base64_encode(serialize($elementQuery));
            $elementQuery->query = $query;
            $elementQuery->subQuery = $subQuery;
            $hash = md5($serialized);

            foreach ($this->_cachedQueries as &$queries) {
                $queries[$hash] = [
                    $elementQuery->elementType,
                    $serialized
                ];
            }
            unset($queries);
        }
    }

    /**
     * Includes an element in any active caches.
     *
     * @param integer $elementId The element ID.
     *
     * @return void
     */
    public function includeElementInTemplateCaches($elementId)
    {
        // Make sure template caching is enabled
        if ($this->_isTemplateCachingEnabled() === false) {
            return;
        }

        if (!empty($this->_cacheElementIds)) {
            foreach ($this->_cacheElementIds as &$elementIds) {
                if (!in_array($elementId, $elementIds, false)) {
                    $elementIds[] = $elementId;
                }
            }
            unset($elementIds);
        }
    }

    /**
     * Ends a template cache.
     *
     * @param string      $key        The template cache key.
     * @param boolean     $global     Whether the cache should be stored globally.
     * @param string|null $duration   How long the cache should be stored for. Should be a [relative time format](http://php.net/manual/en/datetime.formats.relative.php).
     * @param mixed|null  $expiration When the cache should expire.
     * @param string      $body       The contents of the cache.
     *
     * @throws \Exception
     * @return void
     */
    public function endTemplateCache($key, $global, $duration, $expiration, $body)
    {
        // Make sure template caching is enabled
        if ($this->_isTemplateCachingEnabled() === false) {
            return;
        }

        // If there are any transform generation URLs in the body, don't cache it.
        // stripslashes($body) in case the URL has been JS-encoded or something.
        // Can't use getResourceUrl() here because that will append ?d= or ?x= to the URL.
        if (StringHelper::contains(stripslashes($body), UrlHelper::siteUrl(Craft::$app->getConfig()->getResourceTrigger().'/transforms'))) {
            return;
        }

        if (!$global && (strlen($path = $this->_getPath()) > 255)) {
            Craft::warning('Skipped adding '.$key.' to template cache table because the path is > 255 characters: '.$path, __METHOD__);

            return;
        }

        // Encode any 4-byte UTF-8 characters
        $body = StringHelper::encodeMb4($body);

        // Figure out the expiration date
        if ($duration) {
            $expiration = new DateTime($duration);
        }

        if (!$expiration) {
            $cacheDuration = Craft::$app->getConfig()->getCacheDuration();

            if ($cacheDuration <= 0) {
                $cacheDuration = 31536000; // 1 year
            }

            $cacheDuration += time();

            $expiration = new DateTime('@'.$cacheDuration);
        }

        // Save it
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            Craft::$app->getDb()->createCommand()
                ->insert(
                    self::$_templateCachesTable,
                    [
                        'cacheKey' => $key,
                        'siteId' => Craft::$app->getSites()->currentSite->id,
                        'path' => $global ? null : $this->_getPath(),
                        'expiryDate' => Db::prepareDateForDb($expiration),
                        'body' => $body
                    ],
                    false)
                ->execute();

            $cacheId = Craft::$app->getDb()->getLastInsertID(self::$_templateCachesTable);

            // Tag it with any element queries that were executed within the cache
            if (!empty($this->_cachedQueries[$key])) {
                $values = [];
                foreach ($this->_cachedQueries[$key] as $query) {
                    $values[] = [
                        $cacheId,
                        $query[0],
                        $query[1]
                    ];
                }
                Craft::$app->getDb()->createCommand()
                    ->batchInsert(self::$_templateCacheQueriesTable, [
                        'cacheId',
                        'type',
                        'query'
                    ], $values, false)
                    ->execute();
                unset($this->_cachedQueries[$key]);
            }

            // Tag it with any element IDs that were output within the cache
            if (!empty($this->_cacheElementIds[$key])) {
                $values = [];

                foreach ($this->_cacheElementIds[$key] as $elementId) {
                    $values[] = [$cacheId, $elementId];
                }

                Craft::$app->getDb()->createCommand()
                    ->batchInsert(
                        self::$_templateCacheElementsTable,
                        ['cacheId', 'elementId'],
                        $values,
                        false)
                    ->execute();

                unset($this->_cacheElementIds[$key]);
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Deletes a cache by its ID(s).
     *
     * @param integer|array $cacheId The cache ID.
     *
     * @return boolean
     */
    public function deleteCacheById($cacheId)
    {
        if ($this->_deletedAllCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        $affectedRows = Craft::$app->getDb()->createCommand()
            ->delete(self::$_templateCachesTable, ['id' => $cacheId])
            ->execute();

        return (bool)$affectedRows;
    }

    /**
     * Deletes caches by a given element class.
     *
     * @param string $elementType The element class.
     *
     * @return boolean
     */
    public function deleteCachesByElementType($elementType)
    {
        if ($this->_deletedAllCaches || !empty($this->_deletedCachesByElementType[$elementType]) || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        $this->_deletedCachesByElementType[$elementType] = true;

        $cacheIds = (new Query())
            ->select(['cacheId'])
            ->from([self::$_templateCacheQueriesTable])
            ->where(['type' => $elementType])
            ->column();

        if ($cacheIds) {
            Craft::$app->getDb()->createCommand()
                ->delete(
                    self::$_templateCachesTable,
                    ['id' => $cacheIds])
                ->execute();
        }

        return true;
    }

    /**
     * Deletes caches that include a given element(s).
     *
     * @param ElementInterface|ElementInterface[] $elements The element(s) whose caches should be deleted.
     *
     * @return boolean
     */
    public function deleteCachesByElement($elements)
    {
        if ($this->_deletedAllCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        if (!$elements) {
            return false;
        }

        if (is_array($elements)) {
            $firstElement = ArrayHelper::firstValue($elements);
        } else {
            $firstElement = $elements;
            $elements = [$elements];
        }

        $elementType = get_class($firstElement);
        $deleteQueryCaches = empty($this->_deletedCachesByElementType[$elementType]);
        $elementIds = [];

        /** @var Element[] $elements */
        foreach ($elements as $element) {
            $elementIds[] = $element->id;
        }

        return $this->deleteCachesByElementId($elementIds, $deleteQueryCaches);
    }

    /**
     * Deletes caches that include an a given element ID(s).
     *
     * @param integer|array $elementId         The ID of the element(s) whose caches should be cleared.
     * @param boolean       $deleteQueryCaches Whether a DeleteStaleTemplateCaches task should be created, deleting any
     *                                         query caches that may now involve this element, but hadn't previously.
     *                                         (Defaults to `true`.)
     *
     * @return boolean
     */
    public function deleteCachesByElementId($elementId, $deleteQueryCaches = true)
    {
        if ($this->_deletedAllCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        if (!$elementId) {
            return false;
        }

        if ($deleteQueryCaches && Craft::$app->getConfig()->get('cacheElementQueries')) {
            // If there are any pending DeleteStaleTemplateCaches tasks, just append this element to it
            /** @var DeleteStaleTemplateCaches $task */
            $task = Craft::$app->getTasks()->getNextPendingTask(DeleteStaleTemplateCaches::class);

            if ($task) {
                if (!is_array($task->elementId)) {
                    $task->elementId = (array)$task->elementId;
                }

                if (is_array($elementId)) {
                    $task->elementId = array_merge($task->elementId, $elementId);
                } else {
                    $task->elementId[] = $elementId;
                }

                // Make sure there aren't any duplicate element IDs
                $task->elementId = array_unique($task->elementId);

                // Save the task
                Craft::$app->getTasks()->saveTask($task, false);
            } else {
                Craft::$app->getTasks()->queueTask([
                    'type' => DeleteStaleTemplateCaches::class,
                    'elementId' => $elementId
                ]);
            }
        }

        $cacheIds = (new Query())
            ->select(['cacheId'])
            ->distinct(true)
            ->from([self::$_templateCacheElementsTable])
            ->where(['elementId' => $elementId])
            ->column();

        if (!$cacheIds) {
            return false;
        }

        return $this->deleteCacheById($cacheIds);
    }

    /**
     * Deletes caches that include elements that match a given element query's parameters.
     *
     * @param ElementQuery $query The element query that should be used to find elements whose caches
     *                            should be deleted.
     *
     * @return boolean
     */
    public function deleteCachesByElementQuery(ElementQuery $query)
    {
        if ($this->_deletedAllCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        $limit = $query->limit;
        $query->limit(null);
        $elementIds = $query->ids();
        $query->limit($limit);

        return $this->deleteCachesByElementId($elementIds);
    }

    /**
     * Deletes a cache by its key(s).
     *
     * @param integer|array $key The cache key(s) to delete.
     *
     * @return boolean
     */
    public function deleteCachesByKey($key)
    {
        if ($this->_deletedAllCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        $affectedRows = Craft::$app->getDb()->createCommand()
            ->delete(self::$_templateCachesTable, ['cacheKey' => $key])
            ->execute();

        return (bool)$affectedRows;
    }

    /**
     * Deletes any expired caches.
     *
     * @return boolean
     */
    public function deleteExpiredCaches()
    {
        if ($this->_deletedAllCaches || $this->_deletedExpiredCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        $affectedRows = Craft::$app->getDb()->createCommand()
            ->delete(self::$_templateCachesTable, ['<=', 'expiryDate', Db::prepareDateForDb(new \DateTime())])
            ->execute();

        $this->_deletedExpiredCaches = true;

        return (bool)$affectedRows;
    }

    /**
     * Deletes any expired caches if we haven't already done that within the past 24 hours.
     *
     * @return boolean
     */
    public function deleteExpiredCachesIfOverdue()
    {
        // Ignore if we've already done this once during the request
        if ($this->_deletedExpiredCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        $lastCleanupDate = Craft::$app->getCache()->get('lastTemplateCacheCleanupDate');

        if ($lastCleanupDate === false || DateTimeHelper::currentTimeStamp() - $lastCleanupDate > self::$_lastCleanupDateCacheDuration) {
            // Don't do it again for a while
            Craft::$app->getCache()->set('lastTemplateCacheCleanupDate', DateTimeHelper::currentTimeStamp(), self::$_lastCleanupDateCacheDuration);

            return $this->deleteExpiredCaches();
        }

        $this->_deletedExpiredCaches = true;

        return false;
    }

    /**
     * Deletes all the template caches.
     *
     * @return boolean
     */
    public function deleteAllCaches()
    {
        if ($this->_deletedAllCaches || $this->_isTemplateCachingEnabled() === false) {
            return false;
        }

        $this->_deletedAllCaches = true;

        $affectedRows = Craft::$app->getDb()->createCommand()
            ->delete(self::$_templateCachesTable)
            ->execute();

        return (bool)$affectedRows;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether template caching is enabled, based on the 'enableTemplateCaching' config setting.
     *
     * @return boolean Whether template caching is enabled
     */
    private function _isTemplateCachingEnabled()
    {
        if (Craft::$app->getConfig()->get('enableTemplateCaching')) {
            return true;
        }

        return false;
    }

    /**
     * Returns the current request path, including a "site:" or "cp:" prefix.
     *
     * @return string
     */
    private function _getPath()
    {
        if ($this->_path !== null) {
            return $this->_path;
        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_path = 'cp:';
        } else {
            $this->_path = 'site:';
        }

        $this->_path .= Craft::$app->getRequest()->getPathInfo();

        if (($pageNum = Craft::$app->getRequest()->getPageNum()) != 1) {
            $this->_path .= '/'.Craft::$app->getConfig()->get('pageTrigger').$pageNum;
        }

        return $this->_path;
    }
}
