<?php

namespace Phpactor\Completion\Bridge\TolerantParser\SourceCodeFilesystem;

use Generator;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\ResolvedName;
use Phpactor\ClassFileConverter\Domain\FilePath;
use Phpactor\ClassFileConverter\Domain\FileToClass;
use Phpactor\Completion\Bridge\TolerantParser\Qualifier\ClassQualifier;
use Phpactor\Completion\Bridge\TolerantParser\TolerantCompletor;
use Phpactor\Completion\Bridge\TolerantParser\TolerantQualifiable;
use Phpactor\Completion\Bridge\TolerantParser\TolerantQualifier;
use Phpactor\Completion\Core\Range;
use Phpactor\Completion\Core\Suggestion;
use Phpactor\Filesystem\Domain\FilePath as ScfFilePath;
use Phpactor\Filesystem\Domain\Filesystem;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use SplFileInfo;

class ScfClassCompletor implements TolerantCompletor, TolerantQualifiable
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var FileToClass
     */
    private $fileToClass;

    /**
     * @var ClassQualifier
     */
    private $qualifier;

    public function __construct(Filesystem $filesystem, FileToClass $fileToClass)
    {
        $this->filesystem = $filesystem;
        $this->fileToClass = $fileToClass;
        $this->qualifier = new ClassQualifier();
    }

    public function qualifier(): TolerantQualifier
    {
        return new ClassQualifier();
    }

    public function complete(Node $node, TextDocument $source, ByteOffset $offset): Generator
    {
        $files = $this->filesystem->fileList()->phpFiles();

        if ($node instanceof QualifiedName) {
            $files = $files->filter(function (SplFileInfo $file) use ($node) {
                return 0 === strpos($file->getFilename(), $node->getText());
            });
        }

        $count = 0;
        $currentNamespace = $this->getCurrentNamespace($node);
        $imports = $node->getImportTablesForCurrentScope();

        /** @var ScfFilePath $file */
        foreach ($files as $file) {
            $candidates = $this->fileToClass->fileToClassCandidates(FilePath::fromString($file->path()));

            if ($candidates->noneFound()) {
                continue;
            }

            $best = $candidates->best();
            yield Suggestion::createWithOptions(
                $best->name(),
                [
                    'type' => Suggestion::TYPE_CLASS,
                    'short_description' => $best->__toString(),
                    'class_import' => $this->getClassNameForImport($best, $imports, $currentNamespace),
                    'range' => $this->getRange($node, $offset),
                ]
            );
        }
    }

    /**
     * @return TextDocument|null
     */
    private function getClassNameForImport($candidate, array $imports, string $currentNamespace = null)
    {
        $candidateNamespace = $candidate->namespace();

        if ((string) $currentNamespace === (string) $candidateNamespace) {
            return null;
        }

        /** @var ResolvedName $resolvedName */
        foreach ($imports[0] as $resolvedName) {
            if ($candidate->__toString() === $resolvedName->getFullyQualifiedNameText()) {
                return null;
            }
        }

        return $candidate->__toString();
    }

    /**
     * @return string|null
     */
    private function getCurrentNamespace(Node $node)
    {
        $currentNamespaceDefinition = $node->getNamespaceDefinition();

        return null !== $currentNamespaceDefinition && null !== $currentNamespaceDefinition->name
            ? $currentNamespaceDefinition->name->getText()
            : null;
    }

    private function getRange(Node $node, ByteOffset $offset): Range
    {
        if ($node instanceof QualifiedName) {
            return Range::fromStartAndEnd($node->getStart(), $node->getEndPosition());
        }

        return new Range($offset, $offset);
    }
}
