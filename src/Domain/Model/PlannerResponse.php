<?php

declare(strict_types=1);

namespace Semitexa\Llm\Domain\Model;

use Semitexa\Llm\Domain\Enum\PlannerResponseType;

final readonly class PlannerResponse
{
    /**
     * @param PlannerResponseType $type
     * @param string|null $skill Skill name for propose_skill
     * @param array<string, mixed> $arguments Arguments for proposed skill
     * @param string $reason Explanation from the model
     * @param float|null $confidence 0.0-1.0 confidence score
     * @param string|null $message Text content for answer/ask/refuse types
     */
    public function __construct(
        public PlannerResponseType $type,
        public ?string $skill = null,
        public array $arguments = [],
        public string $reason = '',
        public ?float $confidence = null,
        public ?string $message = null,
        public bool $jsonExtractionFailed = false,
    ) {}

    public function toArray(): array
    {
        $data = ['type' => $this->type->value];

        if ($this->skill !== null) {
            $data['skill'] = $this->skill;
        }

        if ($this->arguments) {
            $data['arguments'] = $this->arguments;
        }

        if ($this->reason !== '') {
            $data['reason'] = $this->reason;
        }

        if ($this->confidence !== null) {
            $data['confidence'] = $this->confidence;
        }

        if ($this->message !== null) {
            $data['message'] = $this->message;
        }

        if ($this->jsonExtractionFailed) {
            $data['json_extraction_failed'] = true;
        }

        return $data;
    }
}
