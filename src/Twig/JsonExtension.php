<?php

declare(strict_types=1);

namespace Bolt\Twig;

use Bolt\Common\Json;
use Bolt\Entity\Content;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigTest;

class JsonExtension extends AbstractExtension
{
    private const SERIALIZE_GROUP = 'get_content';

    private const SERIALIZE_GROUP_DEFINITION = 'get_definition';

    private $includeDefinition = true;

    /** @var NormalizerInterface */
    private $normalizer;

    public function __construct(NormalizerInterface $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * {@inheritdoc}
     */
    public function getFilters()
    {
        return [
            new TwigFilter('normalize_records', [$this, 'normalizeRecords']),
            new TwigFilter('json_records', [$this, 'jsonRecords']),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTests()
    {
        return [
            new TwigTest('json', [$this, 'testJson']),
        ];
    }

    public function jsonRecords($records, ?bool $includeDefinition = true, int $options = 0): string
    {
        $this->includeDefinition = $includeDefinition;

        return Json::json_encode($this->normalizeRecords($records), $options);
    }

    /**
     * @param Content|array|\Traversable $records
     */
    public function normalizeRecords($records): array
    {
        if ($records instanceof Content) {
            return $this->contentToArray($records);
        }

        if (is_array($records)) {
            $normalizedRecords = $records;
        } else {
            $normalizedRecords = iterator_to_array($records);
        }

        return array_map([$this, 'contentToArray'], $normalizedRecords);
    }

    private function contentToArray(Content $content): array
    {
        $group = [self::SERIALIZE_GROUP];

        if ($this->includeDefinition) {
            $group[] = self::SERIALIZE_GROUP_DEFINITION;
        }

        // we do it that way because in current API Platform version a Resource
        // can't implement \JsonSerializable
        return $this->normalizer->normalize($content, null, [
            'groups' => $group,
        ]);
    }

    /**
     * Test whether a passed string contains valid JSON.
     */
    public function testJson(string $string): bool
    {
        return Json::test($string);
    }
}
