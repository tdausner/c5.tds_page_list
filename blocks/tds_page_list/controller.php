<?php
namespace Concrete\Package\TdsPageList\Block\TdsPageList;
defined('C5_EXECUTE') or die("Access Denied.");
/**
 * TDS Page List add-on block controller.
 *
 * derived from Concrete\Block\PageList
 *
 * Copyright 2018 - TDSystem Beratung & Training - Thomas Dausner (tdausner)
 */

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Attribute\Key\CollectionKey as CollectionAttributeKey;
use Concrete\Core\Block\BlockController;
use Concrete\Core\Page\Feed;
use Concrete\Core\Tree\Node\Node;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Tree\Node\Type\Topic;
use Concrete\Core\Routing\Redirect;

class Controller extends BlockController
{
    protected $btTable = 'btTdsPageList';
    protected $btInterfaceWidth = 700;
    protected $btInterfaceHeight = 720;
    protected $btDefaultSet = 'navigation';
    protected $allResults = false;
    protected $list = null;

    /**
     * Used for localization. If we want to localize the name/description we have to include this.
     */
    public function getBlockTypeDescription()
    {
        return t("List pages based on type, date or other criteria.");
    }

    public function getBlockTypeName()
    {
        return t("Page List enhanced");
    }

    public function on_start()
    {
        $this->list = new PageList();
        $this->list->disableAutomaticSorting();
        $this->list->setNameSpace('b' . $this->bID);

        switch ($this->orderBy)
        {
            case 'display_asc':
                $this->list->sortByDisplayOrder();
                break;
            case 'display_desc':
                $this->list->sortByDisplayOrderDescending();
                break;
            case 'chrono_asc':
                $this->list->sortByPublicDate();
                break;
            case 'modified_desc':
                $this->list->sortByDateModifiedDescending();
                break;
            case 'random':
                $this->list->sortBy('RAND()');
                break;
            case 'alpha_asc':
                $this->list->sortByName();
                break;
            case 'alpha_desc':
                $this->list->sortByNameDescending();
                break;
            default:
                $this->list->sortByPublicDateDescending();
                break;
        }

        if (!is_object($this->app))
        {
            $this->app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
        }
        $now = $this->app->make('helper/date')->toDB();
        $end = $start = null;

        switch ($this->filterDateOption)
        {
            case 'now':
                $start = date('Y-m-d') . ' 00:00:00';
                $end = $now;
                break;

            case 'past':
                $end = $now;

                if ($this->filterDateDays > 0)
                {
                    $past = date('Y-m-d', strtotime("-{$this->filterDateDays} days"));
                    $start = "$past 00:00:00";
                }
                break;

            case 'future':
                $start = $now;

                if ($this->filterDateDays > 0)
                {
                    $future = date('Y-m-d', strtotime("+{$this->filterDateDays} days"));
                    $end = "$future 23:59:59";
                }
                break;

            case 'between':
                $start = "{$this->filterDateStart} 00:00:00";
                $end = "{$this->filterDateEnd} 23:59:59";
                break;

            case 'all':
            default:
                break;
        }

        if ($start)
        {
            $this->list->filterByPublicDate($start, '>=');
        }
        if ($end)
        {
            $this->list->filterByPublicDate($end, '<=');
        }

        $c = Page::getCurrentPage();
        if (is_object($c))
        {
            $this->cID = $c->getCollectionID();
            $this->cPID = $c->getCollectionParentID();
        }

        if ($this->displayFeaturedOnly == 1)
        {
            $cak = CollectionAttributeKey::getByHandle('is_featured');
            if (is_object($cak))
            {
                $this->list->filterByIsFeatured(1);
            }
        }
        if ($this->displayAliases)
        {
            $this->list->includeAliases();
        }
        if (isset($this->ignorePermissions) && $this->ignorePermissions)
        {
            $this->list->ignorePermissions();
        }

        $this->list->filter('cvName', '', '!=');

        if ($this->ptID)
        {
            $this->list->filterByPageTypeID($this->ptID);
        }

        if ($this->filterByRelated)
        {
            $ak = CollectionKey::getByHandle($this->relatedTopicAttributeKeyHandle);
            if (is_object($ak))
            {
                $topics = $c->getAttribute($ak->getAttributeKeyHandle());
                if (is_array($topics) && count($topics) > 0)
                {
                    $topic = $topics[array_rand($topics)];
                    $this->list->filter('p.cID', $c->getCollectionID(), '<>');
                    $this->list->filterByTopic($topic);
                }
            }
        }

        if ($this->filterByCustomTopic)
        {
            $ak = CollectionKey::getByHandle($this->customTopicAttributeKeyHandle);
            if (is_object($ak))
            {
                $topic = Node::getByID($this->customTopicTreeNodeID);
                if ($topic)
                {
                    $ak->getController()->filterByAttribute($this->list, $this->customTopicTreeNodeID);
                }
            }
        }

        $this->list->filterByExcludePageList(false);

        if (intval($this->cParentID) != 0)
        {
            $cParentID = ($this->cThis) ? $this->cID : (($this->cThisParent) ? $this->cPID : $this->cParentID);
            if ($this->includeAllDescendents)
            {
                $this->list->filterByPath(Page::getByID($cParentID)->getCollectionPath());
            } else
            {
                $this->list->filterByParentID($cParentID);
            }
        }

        if ($this->start > 1 && !$this->paginate)
        {
            $this->list->getQueryObject()->setFirstResult($this->start -1);
        }
        return $this->list;
    }

    public function view()
    {

        $c = $this->getCollectionObject();
        if ($c && strpos($this->orderBy, 'chrono') == 0 && $this->displayResults != 'all' && !(isset($this->allResults) && $this->allResults))
        {

            $page0 = $this->list->getResults()[0];
            if ($page0)
            {
                $dh = $this->app->make('helper/date');
                $date = explode('-', $dh->toDB($dh->formatDate($page0->getCollectionDatePublic(), true)));
                if ($c->isEditMode())
                {
                    $this->filter_by_date($date[0], $this->displayResults == 'year' ? false : $date[1]);
                } else
                {
                    $path = $c->getCollectionLink();
                    $url = $path . '/' . $date[0] . ($this->displayResults == 'year' ? '' : ('/' . $date[1]));
                    Redirect::url($url)->send();
                }
            }
        }

        $list = $this->list;

        if ($this->pfID)
        {
            $this->requireAsset('css', 'font-awesome');
            $feed = Feed::getByID($this->pfID);
            if (is_object($feed))
            {
                $this->set('rssUrl', $feed->getFeedURL());
                $link = $feed->getHeadLinkElement();
                $this->addHeaderItem($link);
            }
        }

        //Pagination...
        $showPagination = false;
        if ($this->num > 0 && $this->paginate)
        {
            $list->setItemsPerPage($this->num);
            $pagination = $list->getPagination();
            $pages = $pagination->getCurrentPageResults();
            if ($pagination->haveToPaginate() && $this->paginate)
            {
                $showPagination = true;
                $pagination = $pagination->renderDefaultView();
                $this->set('pagination', $pagination);
            }
        } else
        {
            $pages = $list->getResults();
            if ($this->num > 0)
            {
                $pages = array_slice($pages, 0, $this->num);
            }

        }

        if ($showPagination)
        {
            $this->requireAsset('css', 'core/frontend/pagination');
        }
        $this->set('pages', $pages);
        $this->set('list', $list);
        $this->set('showPagination', $showPagination);
    }

    public function add()
    {
        $this->requireAsset('core/topics');
        $this->requireAsset('font-awesome');
        $c = Page::getCurrentPage();
        $this->set('c', $c);
        $this->set('num', 0);
        $this->set('start', 1);
        $this->set('displayResults', 'all');
        $this->set('filterDateOption', 'all');
        $this->set('includeDescription', true);
        $this->set('includeName', 1);
        $this->set('pageNameClickable', 1);
        $this->set('nameFormat', '');
        $this->set('includeDate', 'no');
        $this->set('datePos', 'below');
        $this->set('firstBlockOrg', 0);
        $this->set('bt', BlockType::getByHandle('tds_page_list'));
        $this->set('featuredAttribute', CollectionAttributeKey::getByHandle('is_featured'));
        $this->set('thumbnailAttribute', CollectionAttributeKey::getByHandle('thumbnail'));
        $this->set('thumbnailPos', 'left');
        $this->set('thumbnailClickable', 1);
        $this->set('thumbnailMobile', 1);
        $this->loadKeys();
    }

    public function edit()
    {
        $this->requireAsset('core/topics');
        $this->requireAsset('font-awesome');
        $b = $this->getBlockObject();
        $bCID = $b->getBlockCollectionID();
        $bID = $b->getBlockID();
        $this->set('bID', $bID);
        $c = Page::getCurrentPage();
        if ((!$this->cThis) && (!$this->cThisParent) && ($this->cParentID != 0))
        {
            $isOtherPage = true;
            $this->set('isOtherPage', true);
        }
        if ($this->pfID)
        {
            $feed = Feed::getByID($this->pfID);
            if (is_object($feed))
            {
                $this->set('rssFeed', $feed);
            }
        }
        $this->set('bt', BlockType::getByHandle('tds_page_list'));
        $this->set('featuredAttribute', CollectionAttributeKey::getByHandle('is_featured'));
        $this->set('thumbnailAttribute', CollectionAttributeKey::getByHandle('thumbnail'));
        $this->loadKeys();
    }

    private function loadKeys()
    {
        $attributeKeys = [];
        $keys = CollectionKey::getList();
        foreach ($keys as $ak)
        {
            if ($ak->getAttributeTypeHandle() == 'topics')
            {
                $attributeKeys[] = $ak;
            }
        }
        $this->set('attributeKeys', $attributeKeys);
    }

    public function action_filter_by_topic($treeNodeID = false, $topic = false)
    {
        if ($treeNodeID)
        {
            $topicObj = Topic::getByID(intval($treeNodeID));
            if (is_object($topicObj) && $topicObj instanceof Topic)
            {
                $this->list->filterByTopic(intval($treeNodeID));
                $seo = $this->app->make('helper/seo');
                $seo->addTitleSegment($topicObj->getTreeNodeDisplayName());
            }
        }
        $this->view();
    }

    public function action_filter_by_tag($tag = false)
    {
        $seo = $this->app->make('helper/seo');
        $seo->addTitleSegment($tag);
        $this->list->filterByTags(h($tag));
        $this->view();
    }

    public function action_filter_none()
    {
        $this->allResults = true;
        $this->view();
    }

    private function filter_by_date($year, $month = false, $timezone = 'user')
    {
        if ($month)
        {
            $month = str_pad($month, 2, '0', STR_PAD_LEFT);
            $lastDayInMonth = date('t', strtotime("$year-$month-01"));
            $start = "$year-$month-01 00:00:00";
            $end = "$year-$month-$lastDayInMonth 23:59:59";
        } else
        {
            $start = "$year-01-01 00:00:00";
            $end = "$year-12-31 23:59:59";
        }
        $dh = $this->app->make('helper/date');
        /* @var $dh \Concrete\Core\Localization\Service\Date */
        if ($timezone !== 'system')
        {
            $start = $dh->toDB($start, $timezone);
            $end = $dh->toDB($end, $timezone);
        }
        $this->list->filterByPublicDate($start, '>=');
        $this->list->filterByPublicDate($end, '<=');

        $seo = $this->app->make('helper/seo');
        $date = ucfirst(\Punic\Calendar::getMonthName($month, 'wide', '', true) . ' ' . $year);
        $seo->addTitleSegment($date);
    }

    public function action_filter_by_date($year = false, $month = false, $timezone = 'user')
    {
        if (is_numeric($year))
        {
            $year = (($year < 0) ? '-' : '') . str_pad(abs($year), 4, '0', STR_PAD_LEFT);
            $this->filter_by_date($year, $month, $timezone);
            $this->displayResults = 'all'; # to avoid endless recursion in view()
        }
        $this->view();
    }

    public function validate($args)
    {
        $e = $this->app->make('helper/validation/error');
        $vs = $this->app->make('helper/validation/strings');
        $pf = false;
        if ($this->pfID)
        {
            $pf = Feed::getByID($this->pfID);
        }
        if ($args['rss'] && !is_object($pf))
        {
            if (!$vs->handle($args['rssHandle']))
            {
                $e->add(t('Your RSS feed must have a valid URL, containing only letters, numbers or underscores'));
            }
            if (!$vs->notempty($args['rssTitle']))
            {
                $e->add(t('Your RSS feed must have a valid title.'));
            }
            if (!$vs->notempty($args['rssDescription']))
            {
                $e->add(t('Your RSS feed must have a valid description.'));
            }
        }

        return $e;
    }

    public function getPassThruActionAndParameters($parameters)
    {
        if ($parameters[0] == 'topic')
        {
            $method = 'action_filter_by_topic';
            $parameters = array_slice($parameters, 1);
        } elseif ($parameters[0] == 'tag')
        {
            $method = 'action_filter_by_tag';
            $parameters = array_slice($parameters, 1);
        } elseif ($parameters[0] == 'all')
        {
            $method = 'action_filter_none';
        } elseif ($this->app->make('helper/validation/numbers')->integer($parameters[0]))
        {
            // then we're going to treat this as a year.
            $method = 'action_filter_by_date';
            $parameters[0] = intval($parameters[0]);
            if (isset($parameters[1]))
            {
                $parameters[1] = intval($parameters[1]);
            }
        } else
        {
            $parameters = $method = null;
        }

        return [$method, $parameters];
    }

    public function isValidControllerTask($method, $parameters = [])
    {
        if (!$this->enableExternalFiltering)
        {
            return false;
        }

        return parent::isValidControllerTask($method, $parameters);
    }

    public function save($args)
    {
        // If we've gotten to the process() function for this class, we assume that we're in
        // the clear, as far as permissions are concerned (since we check permissions at several
        // points within the dispatcher)
        $db = $this->app->make('database')->connection();

        $bID = $this->bID;
        $c = $this->getCollectionObject();
        if (is_object($c))
        {
            $this->cID = $c->getCollectionID();
            $this->cPID = $c->getCollectionParentID();
        }

        $args += [
            'enableExternalFiltering' => 0,
            'includeAllDescendents' => 0,
            'includeDate' => 'no',
            'truncateSummaries' => 0,
            'displayFeaturedOnly' => 0,
            'topicFilter' => '',
            'displayThumbnail' => 0,
            'displayAliases' => 0,
            'truncateChars' => 0,
            'paginate' => 0,
            'rss' => 0,
            'pfID' => 0,
            'filterDateOption' => 'all',
            'cParentID' => null,
        ];

        $args['num'] = ($args['num'] > 0) ? $args['num'] : 0;
        $args['start'] = ($args['start'] > 1) ? $args['start'] : 1;
        $args['cThis'] = ($args['cParentID'] == $this->cID) ? '1' : '0';
        $args['cThisParent'] = ($args['cParentID'] == $this->cPID) ? '1' : '0';
        $args['cParentID'] = ($args['cParentID'] == 'OTHER') ? (empty($args['cParentIDValue']) ? null : $args['cParentIDValue']) : $args['cParentID'];
        if (!$args['cParentID'])
        {
            $args['cParentID'] = 0;
        }
        $args['enableExternalFiltering'] = ($args['enableExternalFiltering']) ? '1' : '0';
        $args['includeAllDescendents'] = ($args['includeAllDescendents']) ? '1' : '0';
        $args['includeName'] = intval($args['includeName']);
        $args['pageNameClickable'] = ($args['pageNameClickable']) ? '1' : '0';
        $args['truncateSummaries'] = ($args['truncateSummaries']) ? '1' : '0';
        $args['displayFeaturedOnly'] = ($args['displayFeaturedOnly']) ? '1' : '0';
        $args['filterByRelated'] = ($args['topicFilter'] == 'related') ? '1' : '0';
        $args['filterByCustomTopic'] = ($args['topicFilter'] == 'custom') ? '1' : '0';
        $args['displayThumbnail'] = intval($args['displayThumbnail']);
        $args['displayAliases'] = ($args['displayAliases']) ? '1' : '0';
        $args['thumbnailClickable'] = ($args['thumbnailClickable']) ? '1' : '0';
        $args['thumbnailMobile'] = ($args['thumbnailMobile']) ? '1' : '0';
        $args['truncateChars'] = intval($args['truncateChars']);
        $args['paginate'] = intval($args['paginate']);
        $args['rss'] = intval($args['rss']);
        $args['ptID'] = intval($args['ptID']);

        if (!$args['filterByRelated'])
        {
            $args['relatedTopicAttributeKeyHandle'] = '';
        }
        if (!$args['filterByCustomTopic'])
        {
            $args['customTopicAttributeKeyHandle'] = '';
            $args['customTopicTreeNodeID'] = 0;
        }

        if ($args['rss'])
        {
            if (isset($this->pfID) && $this->pfID)
            {
                $pf = Feed::getByID($this->pfID);
            }

            if (!is_object($pf))
            {
                $pf = new \Concrete\Core\Entity\Page\Feed();
                $pf->setTitle($args['rssTitle']);
                $pf->setDescription($args['rssDescription']);
                $pf->setHandle($args['rssHandle']);
            }

            $pf->setParentID($args['cParentID']);
            $pf->setPageTypeID($args['ptID']);
            $pf->setIncludeAllDescendents($args['includeAllDescendents']);
            $pf->setDisplayAliases($args['displayAliases']);
            $pf->setDisplayFeaturedOnly($args['displayFeaturedOnly']);
            $pf->setDisplayAliases($args['displayAliases']);
            $pf->displayShortDescriptionContent();
            $pf->save();
            $args['pfID'] = $pf->getID();
        } elseif (isset($this->pfID) && $this->pfID && !$args['rss'])
        {
            // let's make sure this isn't in use elsewhere.
            $cnt = $db->fetchColumn('select count(pfID) from btPageList where pfID = ?', [$this->pfID]);
            if ($cnt == 1)
            { // this is the last one, so we delete
                $pf = Feed::getByID($this->pfID);
                if (is_object($pf))
                {
                    $pf->delete();
                }
            }
            $args['pfID'] = 0;
        }

        if ($args['filterDateOption'] != 'between')
        {
            $args['filterDateStart'] = null;
            $args['filterDateEnd'] = null;
        }

        if ($args['filterDateOption'] == 'past')
        {
            $args['filterDateDays'] = $args['filterDatePast'];
        } elseif ($args['filterDateOption'] == 'future')
        {
            $args['filterDateDays'] = $args['filterDateFuture'];
        } else
        {
            $args['filterDateDays'] = null;
        }

        $args['pfID'] = intval($args['pfID']);
        parent::save($args);
    }

    public function isBlockEmpty()
    {
        $pages = $this->get('pages');
        if (isset($this->pageListTitle) && $this->pageListTitle)
        {
            return false;
        }
        if (empty($pages))
        {
            if ($this->noResultsMessage)
            {
                return false;
            } else
            {
                return true;
            }
        } else
        {
            if ($this->includeName > 0 || $this->includeDate || $this->displayThumbnail
                || $this->includeDescription || $this->useButtonForLink
            )
            {
                return false;
            } else
            {
                return true;
            }
        }
    }

    public function cacheBlockOutput()
    {
        if ($this->btCacheBlockOutput === null)
        {
            if (!$this->enableExternalFiltering && !$this->paginate)
            {
                $this->btCacheBlockOutput = true;
            } else
            {
                $this->btCacheBlockOutput = false;
            }
        }

        return $this->btCacheBlockOutput;
    }
}
