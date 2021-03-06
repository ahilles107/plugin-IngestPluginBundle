<?php
/**
 * @category  IngestPlugin
 * @package   Newscoop\IngestPluginBundle
 * @author    Mischa Gorinskat <mischa.gorinskat@sourcefabric.org>
 * @copyright 2013 Sourcefabric o.p.s.
 * @license   http://www.gnu.org/licenses/gpl-3.0.txt  GNU GENERAL PUBLIC LICENSE Version 3
 */

namespace Newscoop\IngestPluginBundle\Services;

use Doctrine\ORM\EntityManager,
    Doctrine\Common\Collections\Collection,
    Newscoop\IngestPluginBundle\Entity\Ingest\Feed\Entry,
    Newscoop\IngestPluginBundle\Services\ArticleTypeConfigurationService;

/**
 * Ingest publisher service
 */
class PublisherService
{
    /**
     * Initialize service
     *
     * @param EntityManager $em Entity manager
     */
    public function __construct(EntityManager $em, ArticleTypeConfigurationService $articleTypeConfService)
    {
        $this->em = $em;
        $this->atcf = $articleTypeConfService;
    }

    /**
     * Currently just a future proof wrapper class which calls legacy publishing
     * code
     *
     * @param  \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry Entry
     */
    public function publish(\Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry)
    {
        return $this->publishLegacy($entry);
    }

    /**
     * Publishes an entry as an article
     * NOTE: Partially finished and partially tested, got stuck at Article Entity
     * TODO: Fix this correctly and replace publishLegacy() with this
     *
     * @param \NewscoopIngestPluginBundleEntityFeedEntry $entry Entry to be published
     */
    private function publishNew(\Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry)
    {
        if ($this->getArticle($entry) !== null) {
            // Update article
            $this->update($entry);
        } else {

            $publication = $entry->getFeed()->getPublication();
            $latestIssue = $this->em
                ->getRepository('\Newscoop\Entity\Issue')
                ->getLatestByPublication($publication);
            $articleType = $this->em
                ->getRepository('\Newscoop\Entity\ArticleType')
                ->findOneBy(array('name', 'Newswire'));

            // Map data
            $mappingArray = $this->atcf->getArticleTypeMapping();
            $dataArray  = array();
            foreach ($mappingArray as $fieldID => $method) {
                $dataArray[$fieldID] = $entry->$method();
            }

            // Create new Article
            $article = new \Newscoop\Entity\Article(0, $entry->getLanguage());

            // Set title and typeData
            $article
                ->author($entry->getTitle(), $dataArray);

            // Main article settings
            $article
                ->setType($articleType)
                ->setPublication($publication)
                ->setIssue($latestIssue)
                ->setSection($entry->getSection())
                ->setKeywords($entry->getKeywords())
                ->setCommentsEnabled(0);

            $this->setArticleAuthors($article, $entry);

            // TODO: set images

            // Publish article
            $article->publish();

            // Save changes
            $this->em->persist($article);
            $entry->setArticleId($article->getNumber());
            $this->em->persist($entry);
            $this->em->flush();
        }
    }

    /**
     * Publishes an entry as an article.
     * NOTE: Mixed new and legacy code. Legacy article API is used.
     *
     * @param \NewscoopIngestPluginBundleEntityFeedEntry $entry Entry to be published
     */
    private function publishLegacy(\Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry)
    {
        $publication = $entry->getFeed()->getPublication();
        $latestIssue = $this->em
            ->getRepository('\Newscoop\Entity\Issue')
            ->getLatestByPublication($publication);
        $articleType = $this->em
            ->getRepository('\Newscoop\Entity\ArticleType')
            ->findOneByName('Newswire');

        $article = new \Article($entry->getLanguage()->getId());
        $article->create(
            $articleType->getName(),
            $entry->getTitle(),
            $publication->getId(),
            $latestIssue->getId(),
            $entry->getSection()->getId()
        );
        $article->setWorkflowStatus('Y');
        $article->setKeywords(implode(',', $entry->getKeywords()));
        $article->setCommentsEnabled(true);

        // ArticleType data
        $this->setArticleDataLegacy($article, $entry);

        // Dates
        $entry->setPublished(new \DateTime());
        $article->setCreationDate($entry->getCreated()->format('Y-m-d H:i:s'));
        $article->setPublishDate($entry->getPublished()->format('Y-m-d H:i:s'));
        $article->setProperty('time_updated', $entry->getUpdated()->format('Y-m-d H:i:s'));

        // Author
        $this->setArticleAuthorsLegacy($article, $entry);

        // TODO: Images

        $entry->setArticleId($article->getArticleNumber());

        $article->commit();
        $this->em->persist($entry);
        $this->em->flush();

        return $article;
    }

    public function update(\Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry)
    {
        // update
    }

    public function remove(\Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry)
    {
        $article = $this->getArticle($entry);
        if ($article !== null) {
            $this->em->remove($article);
            $this->em->flush();
            exit;
        }
    }

    private function getArticle(\Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry)
    {
        if ($entry->getArticleId() === null) return null;

        $article = $this->em->getRepository('\Newscoop\Entity\Article')
            ->findOneByNumber($entry->getArticleId());

        return $article;
    }

    /**
     * Set articletype specific data
     *
     * @param \Newscoop\Entity\Article                       $article Article
     * @param \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry   Entity
     */
    private function setArticleData(
            \Newscoop\Entity\Article $article,
            \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry
        )
    {
        $newswireType = $this->em
            ->getRepository('\Newscoop\Entity\ArticleType')
            ->findBy(array('name' => 'newswire'));

        // Get fields for
        $newswireFields = $this->em
            ->getRepository('\Newscoop\Entity\ArticleTypeField')
            ->findBy(array('type_id' => $newswireType->getId()));

        // TODO: Finish this
    }

    /**
     * Set articletype data the legacy way
     *
     * @param \Article                                       $article Article
     * @param \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry   Entity
     */
    private function setArticleDataLegacy(
        \Article $article,
        \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry
    ) {
        // Get mapping data from ArticleTypeConfiguration service
        $mappingArray = $this->atcf->getArticleTypeMapping();

        $data = $article->getArticleData();
        foreach ($mappingArray as $fieldID => $method) {
            if (method_exists($entry, $method)) {
                $propertySet = $data->setProperty("F{$fieldID}", $entry->$method());
            }
        }

        $data->create();
    }

    /**
     * Set authors for article, if author doesn't exist it gets created
     *
     * @param \Newscoop\Entity\Article                       $article Article
     * @param \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry   Entity
     */
    private function setArticleAuthors(
            \Newscoop\Entity\Article $article,
            \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry
        )
    {
        $repository = $this->em->getRepository('\Newscoop\Entity\Author');
        $doctrineCollection = new \Doctrine\Common\Collections\ArrayCollection();

        $authors    = $entry->getAuthors();
        if (count($authors) > 0) {
            foreach ($authors as $author) {

                $authorEntity = $repository
                    ->findBy(array(
                        'first_name' => $author['firstname'],
                        'last_name' => $author['lastname']
                    ));

                if ($authorEntity === null) {
                    $authorEntity = new \Newscoop\Entity\Author($author['firstname'], $author['lastname']);
                }

                $doctrineCollection->add($authorEntity);
            }

            $article->setArticleAuthors($doctrineCollection);
        }
    }

    /**
     * Set authors for an article, uses legacy classes
     *
     * @param Article                                   $article
     * @param \Newscoop\IngestPluginBundle\Entity\Entry $entry
     */
    private function setArticleAuthorsLegacy(
        \Article $article,
        \Newscoop\IngestPluginBundle\Entity\Feed\Entry $entry
    ) {
        $authors    = $entry->getAuthors();
        $order      = 0;
        if (count($authors) > 0) {
            foreach ($authors as $author) {
                $name = trim($author['firstname'] .' '. $author['lastname']);
                $author = new \Author($name);
                if (!$author->exists()) {
                    $author->create();
                }
                $article->setAuthor($author, $order++);
            }
        } else {
            $name = $entry->getProduct() ?: $entry->getFeed()->getTitle();
            $author = new \Author($name);
            if (!$author->exists()) {
                $author->create();
            }
            $article->setAuthor($author);
        }
    }
}
