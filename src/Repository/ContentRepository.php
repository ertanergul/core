<?php

declare(strict_types=1);

namespace Bolt\Repository;

use Bolt\Configuration\Content\ContentType;
use Bolt\Doctrine\JsonHelper;
use Bolt\Entity\Content;
use Bolt\Enum\Statuses;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Tightenco\Collect\Support\Collection;

/**
 * @method Content|null find($id, $lockMode = null, $lockVersion = null)
 * @method Content|null findOneBy(array $criteria, array $orderBy = null)
 * @method Content[]    findAll()
 * @method Content[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContentRepository extends ServiceEntityRepository
{
    private $contentColumns = ['id', 'author', 'contentType', 'status', 'createdAt', 'modifiedAt', 'publishedAt', 'depublishedAt'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Content::class);
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('content');
    }

    public function findForTaxonomy(int $page, Collection $taxonomy, string $slug, int $amountPerPage, bool $onlyPublished = true): Pagerfanta
    {
        $qb = $this->getQueryBuilder()
            ->addSelect('a')
            ->innerJoin('content.author', 'a');

        $qb->addSelect('t')
            ->innerJoin('content.taxonomies', 't')
            ->andWhere('t.type = :taxonomyslug')
            ->setParameter('taxonomyslug', $taxonomy->get('slug'))
            ->andWhere('t.slug = :slug')
            ->setParameter('slug', $slug);

        if ($onlyPublished) {
            $qb->andWhere('content.status = :status')
                ->setParameter('status', Statuses::PUBLISHED);
        }

        [ $order, $direction, $sortByField ] = $this->createSortBy($taxonomy);

        if (! $sortByField) {
            $qb->orderBy('content.' . $order, $direction);
        }

        return $this->createPaginator($qb->getQuery(), $page, $amountPerPage);
    }

    public function findLatest(Collection $contentTypes, int $page = 1, int $amount = 6): Pagerfanta
    {
        $qb = $this->getQueryBuilder()
            ->addSelect('a')
            ->innerJoin('content.author', 'a')
            ->orderBy('content.modifiedAt', 'DESC');

        if ($contentTypes->has('slug')) {
            $cts = [$contentTypes->get('slug')];
        } else {
            $cts = $contentTypes->pluck('slug')->all();
        }

        $qb->where('content.contentType IN (:cts)')
            ->setParameter('cts', $cts);

        $qb->setMaxResults($amount);

        return $this->createPaginator($qb->getQuery(), $page, $amount);
    }

    public function searchNaive(string $searchTerm, int $page, int $amountPerPage, Collection $contentTypes, bool $onlyPublished = true): Pagerfanta
    {
        // First, create a querybuilder to get the fields that match the Query
        $qb = $this->getQueryBuilder()
            ->select('partial content.{id}');

        $qb->addSelect('f')
            ->innerJoin('content.fields', 'f')
            ->innerJoin('f.translations', 't')
            ->andWhere($qb->expr()->like('t.value', ':search'))
            ->setParameter('search', '%' . $searchTerm . '%');

        // These are the ID's of content we need.
        $ids = array_column($qb->getQuery()->getArrayResult(), 'id');

        // Next, we'll get the full Content objects, based on ID's
        $qb = $this->getQueryBuilder()
            ->addSelect('a')
            ->innerJoin('content.author', 'a')
            ->orderBy('content.modifiedAt', 'DESC');

        if ($onlyPublished) {
            $qb->andWhere('content.status = :status')
                ->setParameter('status', Statuses::PUBLISHED);
        }

        $qb->andWhere('content.contentType IN (:cts)')
            ->setParameter('cts', $contentTypes->keys()->all());

        $qb->andWhere('content.id IN (:ids)')
            ->setParameter('ids', $ids);

        return $this->createPaginator($qb->getQuery(), $page, $amountPerPage);
    }

    public function findOneById(int $id): ?Content
    {
        return $this->find($id);
    }

    public function findOneBySlug(string $slug, ?ContentType $contentType = null): ?Content
    {
        $qb = $this->getQueryBuilder();
        $connection = $qb->getEntityManager()->getConnection();

        [$where, $slug] = JsonHelper::wrapJsonFunction('translations.value', $slug, $connection);

        $query = $qb
            ->innerJoin('content.fields', 'field')
            ->innerJoin(
                \Bolt\Entity\Field\SlugField::class,
                'slug',
                'WITH',
                'field.id = slug.id'
            )
            ->innerJoin('field.translations', 'translations')
            ->andWhere($where . ' = :slug')
            ->setParameter('slug', $slug);

        if ($contentType) {
            $query->andWhere('content.contentType = :ct')
                ->setParameter('ct', $contentType->get('slug'));
        }

        return $query->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByFieldValue(string $fieldName, string $value, ?ContentType $contentType = null): ?Content
    {
        $qb = $this->getQueryBuilder();
        $connection = $qb->getEntityManager()->getConnection();

        [$where, $value] = JsonHelper::wrapJsonFunction('translation.value', $value, $connection);

        $query = $qb
            ->innerJoin('content.fields', 'field')
            ->innerJoin('field.translations', 'translation')
            ->andWhere($where . ' = :value')
            ->setParameter('value', $value)
            ->andWhere('field.name = :name')
            ->setParameter('name', $fieldName);

        if ($contentType) {
            $query->andWhere('content.contentType = :ct')
                ->setParameter('ct', $contentType->get('slug'));
        }

        return $query->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function createPaginator(Query $query, int $page, int $amountPerPage): Pagerfanta
    {
        $paginator = new Pagerfanta(new DoctrineORMAdapter($query, true, true));
        $paginator->setMaxPerPage($amountPerPage);
        $paginator->setCurrentPage($page);
        return $paginator;
    }

    public function findAdjacentBy(string $column, string $direction, int $currentValue, ?string $contentType = null): ?Content
    {
        if ($direction === 'next') {
            $order = 'ASC';
            $whereClause = 'content.' . $column .' > :value';
        } else {
            $order = 'DESC';
            $whereClause = 'content.' . $column .' < :value';
        }

        $qb = $this->getQueryBuilder()
            ->addSelect('a')
            ->innerJoin('content.author', 'a')
            ->orderBy('content.' . $column, $order)
            ->where($whereClause)
            ->setParameter('value', $currentValue)
            ->andWhere('content.status = :status')
            ->setParameter('status', Statuses::PUBLISHED)
            ->setMaxResults(1);

        if ($contentType) {
            $qb->andWhere('content.contentType = :contentType')
                ->setParameter('contentType', $contentType);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Cobble together the sorting order, and whether or not it's a column in `content` or `fields`.
     */
    private function createSortBy(Collection $contentType): array
    {
        $order = $contentType->get('sort', '');

        if (mb_strpos($order, '-') === 0) {
            $direction = 'DESC';
            $order = mb_substr($order, 1);
        } elseif (mb_strpos($order, ' DESC') !== false) {
            $direction = 'DESC';
            $order = str_replace(' DESC', '', $order);
        } else {
            $order = str_replace(' ASC', '', $order);
            $direction = 'ASC';
        }

        if (\in_array($order, $this->contentColumns, true)) {
            $sortByField = false;
        } else {
            $sortByField = true;
        }

        return [$order, $direction, $sortByField];
    }
}
