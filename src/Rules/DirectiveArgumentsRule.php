<?php

declare(strict_types=1);

namespace Forte\Sheath\Statamic\Rules;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Statamic\Parsing\TagsDirective\Scanner as TagsDirectiveScanner;

#[RequiresPackage('statamic/cms', '^6.0')]
final class DirectiveArgumentsRule extends BaseRule
{
    /** @var array<string, string> */
    private const REQUIRED = [
        'tags' => '`@tags` needs a tag name or tag definition.',
        'frontmatter' => '`@frontmatter` needs an array expression.',
        'nocache' => '`@nocache` needs a content expression.',
    ];

    public function getId(): string
    {
        return 'statamic-directive-arguments';
    }

    public function getDescription(): string
    {
        return 'Requires arguments for `@tags`, `@frontmatter`, and `@nocache`.';
    }

    public function check(Document $document, RuleContext $context): void
    {
        foreach ($document->allOfType(DirectiveNode::class, true) as $directive) {
            $name = strtolower($directive->nameText());
            if (isset(self::REQUIRED[$name]) && $this->hasEmptyArguments($directive->arguments())) {
                $context->report($directive, self::REQUIRED[$name]);
            }
        }

        foreach (app(TagsDirectiveScanner::class)->issues($document) as $issue) {
            $this->reportRange(
                $context,
                $document,
                $issue->start,
                $issue->end,
                '`@tags` definitions must be tag-name strings or non-empty tag parameter arrays.',
            );
        }
    }

    private function hasEmptyArguments(?string $arguments): bool
    {
        $arguments = trim($arguments ?? '');
        if ($arguments === '') {
            return true;
        }

        return str_starts_with($arguments, '(')
            && str_ends_with($arguments, ')')
            && trim(substr($arguments, 1, -1)) === '';
    }
}
