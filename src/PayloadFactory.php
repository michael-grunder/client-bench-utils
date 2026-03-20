<?php

declare(strict_types=1);

namespace Mike\BenchUtils;

final class PayloadFactory
{
    private string $stringTemplate;

    /**
     * @var list<string>
     */
    private array $listTemplate;

    /**
     * @var array<string, string>
     */
    private array $hashTemplate;

    /**
     * @var list<string>
     */
    private array $setTemplate;

    /**
     * @var list<string>
     */
    private array $zsetMembers;

    /**
     * @var list<int|string>
     */
    private array $zsetPairs;

    public function __construct(private readonly int $maxKeySize)
    {
        if ($maxKeySize < 1) {
            throw new \InvalidArgumentException('maxKeySize must be at least 1.');
        }

        $stringLength = max(16, $maxKeySize * 32);
        $collectionLength = max(4, $maxKeySize * 2);

        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $buffer = '';

        while (strlen($buffer) < $stringLength) {
            $buffer .= $alphabet;
        }

        $this->stringTemplate = substr($buffer, 0, $stringLength);
        $this->listTemplate = [];
        $this->hashTemplate = [];
        $this->setTemplate = [];
        $this->zsetMembers = [];
        $this->zsetPairs = [];

        for ($index = 0; $index < $collectionLength; $index++) {
            $member = sprintf('member:%d:%s', $index, substr($this->stringTemplate, 0, min($stringLength, 12 + ($index % 17))));
            $field = sprintf('field:%d', $index);
            $value = sprintf('value:%d:%s', $index, substr($this->stringTemplate, 0, min($stringLength, 24 + (($index * 3) % 29))));

            $this->listTemplate[] = $member;
            $this->hashTemplate[$field] = $value;
            $this->setTemplate[] = $member;
            $this->zsetMembers[] = $member;
            $this->zsetPairs[] = $index + 1;
            $this->zsetPairs[] = $member;
        }
    }

    public function maxStringLength(): int
    {
        return strlen($this->stringTemplate);
    }

    public function maxCollectionLength(): int
    {
        return count($this->listTemplate);
    }

    public function string(int $variant): string
    {
        return substr($this->stringTemplate, 0, $this->normalizedSize($variant, $this->maxStringLength()));
    }

    /**
     * @return array<string, string>
     */
    public function hash(int $variant): array
    {
        return array_slice($this->hashTemplate, 0, $this->normalizedSize($variant, $this->maxCollectionLength()), true);
    }

    public function hashFieldName(int $variant): string
    {
        $index = $this->normalizedIndex($variant, $this->maxCollectionLength());

        return sprintf('field:%d', $index);
    }

    public function hashFieldValue(int $variant): string
    {
        $index = $this->normalizedIndex($variant, $this->maxCollectionLength());

        return sprintf('value:%d:%s', $index, substr($this->stringTemplate, 0, min($this->maxStringLength(), 24 + (($index * 3) % 29))));
    }

    /**
     * @return list<string>
     */
    public function list(int $variant): array
    {
        return array_slice($this->listTemplate, 0, $this->normalizedSize($variant, $this->maxCollectionLength()));
    }

    /**
     * @return list<string>
     */
    public function set(int $variant): array
    {
        return array_slice($this->setTemplate, 0, $this->normalizedSize($variant, $this->maxCollectionLength()));
    }

    /**
     * @return list<int|string>
     */
    public function zsetPairs(int $variant): array
    {
        $size = $this->normalizedSize($variant, $this->maxCollectionLength());

        return array_slice($this->zsetPairs, 0, $size * 2);
    }

    public function zsetMember(int $variant): string
    {
        $index = $this->normalizedIndex($variant, $this->maxCollectionLength());

        return $this->zsetMembers[$index];
    }

    public function rangeStop(int $variant): int
    {
        return $this->normalizedSize($variant, $this->maxCollectionLength()) - 1;
    }

    private function normalizedSize(int $variant, int $max): int
    {
        return max(1, min($max, $variant));
    }

    private function normalizedIndex(int $variant, int $max): int
    {
        return $this->normalizedSize($variant, $max) - 1;
    }
}
