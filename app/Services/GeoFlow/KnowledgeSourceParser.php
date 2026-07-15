<?php

namespace App\Services\GeoFlow;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class KnowledgeSourceParser
{
    public function storeUploadedKnowledgeFile(UploadedFile $file, string $relativeDirectory = 'uploads/knowledge'): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'txt');
        $filename = uniqid('', true).'.'.$extension;
        $relativePath = Storage::disk('local')->putFileAs($relativeDirectory, $file, $filename);
        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
        }

        return $relativePath;
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function uploadedKnowledgeFiles(Request $request): array
    {
        return $this->uploadedFilesFromFields($request, ['knowledge_file', 'knowledge_files']);
    }

    /**
     * @param  list<string>  $fieldNames
     * @return array<int, UploadedFile>
     */
    public function uploadedFilesFromFields(Request $request, array $fieldNames): array
    {
        $files = [];

        foreach ($fieldNames as $fieldName) {
            $uploaded = $request->file($fieldName, []);
            if ($uploaded instanceof UploadedFile) {
                $files[] = $uploaded;
                continue;
            }

            if (is_array($uploaded)) {
                foreach ($uploaded as $file) {
                    if ($file instanceof UploadedFile) {
                        $files[] = $file;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @param  array<int, string>  $storedPaths
     * @return array<int, array{content:string,file_type:string,original_name:string,file_path:string}>
     */
    public function parseUploadedKnowledgeFiles(array $uploadedFiles, array &$storedPaths, string $relativeDirectory = 'uploads/knowledge'): array
    {
        $parsedFiles = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $storedRelativePath = $this->storeUploadedKnowledgeFile($uploadedFile, $relativeDirectory);
            $storedPaths[] = $storedRelativePath;
            $parsed = $this->parseUploadedKnowledgeFile(
                Storage::disk('local')->path($storedRelativePath),
                $uploadedFile->getClientOriginalName()
            );

            $parsedFiles[] = [
                'content' => $parsed['content'],
                'file_type' => $parsed['file_type'],
                'original_name' => (string) $uploadedFile->getClientOriginalName(),
                'file_path' => $storedRelativePath,
            ];
        }

        return $parsedFiles;
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    public function mergeKnowledgeSources(string $manualContent, array $parsedFiles): string
    {
        if ($manualContent !== '' && $parsedFiles === []) {
            return $manualContent;
        }

        $blocks = [];
        if ($manualContent !== '') {
            $blocks[] = "# 手动输入内容\n\n".$manualContent;
        }

        foreach ($parsedFiles as $parsedFile) {
            $fileName = trim((string) $parsedFile['original_name']);
            $blocks[] = '# 文件：'.$fileName."\n\n".trim((string) $parsedFile['content']);
        }

        return $this->normalizeKnowledgeText(implode("\n\n---\n\n", $blocks));
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     */
    public function inferKnowledgeName(array $uploadedFiles): string
    {
        if ($uploadedFiles === []) {
            return '';
        }

        $firstName = pathinfo((string) $uploadedFiles[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $firstName = trim($firstName);
        if (count($uploadedFiles) === 1) {
            return $firstName;
        }

        return $firstName === ''
            ? __('admin.knowledge_bases.imported_multi_file_name', ['count' => count($uploadedFiles)])
            : __('admin.knowledge_bases.imported_multi_file_name_with_first', [
                'name' => $firstName,
                'count' => count($uploadedFiles),
            ]);
    }

    public function inferKnowledgeNameFromContent(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        foreach ($lines as $line) {
            $candidate = trim((string) $line);
            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/^#{1,6}\s*/u', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/^[-*+]\s+/u', '', $candidate) ?? $candidate;
            $candidate = trim(strip_tags($candidate));
            $candidate = trim($candidate, " \t\n\r\0\x0B#*_`>");

            if ($candidate !== '') {
                return mb_substr($candidate, 0, 60, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    public function resolveKnowledgeFileType(string $requestedType, string $manualContent, array $parsedFiles): string
    {
        if ($parsedFiles === []) {
            return in_array($requestedType, ['markdown', 'word', 'text'], true) ? $requestedType : 'markdown';
        }

        if ($manualContent !== '' || count($parsedFiles) > 1) {
            return 'markdown';
        }

        $fileType = (string) ($parsedFiles[0]['file_type'] ?? 'markdown');

        return in_array($fileType, ['markdown', 'word', 'text'], true) ? $fileType : 'markdown';
    }

    /**
     * @param  array<int, string>  $storedPaths
     */
    public function encodeKnowledgeFilePaths(array $storedPaths): string
    {
        if ($storedPaths === []) {
            return '';
        }

        if (count($storedPaths) === 1) {
            return (string) $storedPaths[0];
        }

        return (string) json_encode(array_values($storedPaths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{content:string,file_type:string}
     */
    public function parseUploadedKnowledgeFile(string $absolutePath, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, ['txt', 'md', 'markdown'], true)) {
            $raw = @file_get_contents($absolutePath);
            if ($raw === false) {
                throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
            }

            $content = $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($raw));
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            return [
                'content' => $content,
                'file_type' => in_array($extension, ['md', 'markdown'], true) ? 'markdown' : 'text',
            ];
        }

        if ($extension === 'docx') {
            $content = $this->extractDocxContent($absolutePath);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
            }

            return [
                'content' => $content,
                'file_type' => 'word',
            ];
        }

        // PDF: 尝试提取文字
        if ($extension === 'pdf') {
            $content = $this->extractPdfContent($absolutePath);
            return ['content' => $content ?: '(PDF文件，文字提取有限，建议同时上传txt/md版本)', 'file_type' => 'pdf'];
        }

        // PPTX/XLSX: ZIP包内XML提取
        if (in_array($extension, ['pptx','xlsx'], true)) {
            $content = $this->extractOfficeXmlText($absolutePath, $extension);
            $ft = $extension === 'pptx' ? 'powerpoint' : 'excel';
            return ['content' => $content ?: "(无法解析{$extension}内容)", 'file_type' => $ft];
        }

        // CSV: 直接读文本
        if ($extension === 'csv') {
            $content = @file_get_contents($absolutePath);
            if ($content !== false) {
                return ['content' => $this->normalizeKnowledgeText($content), 'file_type' => 'csv'];
            }
        }

        // PPT/XLS(旧格式)等: 存储文件
        $formats = ['ppt'=>'powerpoint','xls'=>'excel'];
        $ft = $formats[$extension] ?? 'binary';
        return [
            'content' => "(二进制文件，类型: {$extension}) 文件已上传。建议另存为 pptx/xlsx 格式以获得文字解析。",
            'file_type' => $ft,
        ];
    }

    /** PDF文字提取（基础——提取文本流内容） */
    private function extractPdfContent(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) return '';
        $text = '';
        // 提取 BT/ET 之间的文本
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/\(([^)]*)\)/', $block, $chars)) {
                    $text .= implode('', $chars[1]) . "\n";
                }
            }
        }
        // 也尝试提取流内容
        if (empty(trim($text)) && preg_match_all('/stream\s*(.*?)\s*endstream/s', $content, $streams)) {
            foreach ($streams[1] as $s) {
                $decoded = @gzuncompress($s) ?: $s;
                if (preg_match_all('/\(([^)]*)\)/', $decoded, $chars)) {
                    $text .= implode('', $chars[1]) . "\n";
                }
            }
        }
        return $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($text));
    }

    /** PPTX/XLSX: ZIP内XML文本提取 */
    private function extractOfficeXmlText(string $path, string $ext): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) return '';

        // 辅助: 移除命名空间前缀让正则匹配更简单
        $cleanXml = fn(string $xml): string => preg_replace('/<\/?[a-z]+:/', '</', preg_replace('/<[a-z]+:/', '<', $xml));

        $texts = [];
        if ($ext === 'pptx') {
            // PPTX: 逐页提取 slide XML
            for ($i = 1; $i <= 100; $i++) {
                $xml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                if ($xml === false) break;
                $texts[] = $this->stripXmlTags($cleanXml($xml));
            }
            // 也提取备注
            for ($i = 1; $i <= 100; $i++) {
                $notes = $zip->getFromName("ppt/notesSlides/notesSlide{$i}.xml");
                if ($notes === false) break;
                $texts[] = $this->stripXmlTags($notes);
            }
        } elseif ($ext === 'xlsx') {
            // XLSX: strip_tags提取文本（最可靠，兼容所有命名空间）
            $ss = $zip->getFromName('xl/sharedStrings.xml');
            $sharedStrings = [];
            if ($ss !== false) {
                preg_match_all('/<si[^>]*>(.*?)<\/si>/s', $ss, $siMatches);
                foreach ($siMatches[1] as $si) {
                    $sharedStrings[] = trim(strip_tags($si));
                }
            }
            for ($i = 1; $i <= 100; $i++) {
                $sheet = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheet === false) break;
                preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rows);
                $rowTexts = [];
                foreach ($rows[1] as $row) {
                    // 提取每个单元格的内容和类型
                    preg_match_all('/<c[^>]*>(.*?)<\/c>/s', $row, $cellMatches);
                    $cells = [];
                    foreach ($cellMatches[1] as $ci => $inner) {
                        $fullTag = $cellMatches[0][$ci];
                        $isShared = strpos($fullTag, 't="s"') !== false;
                        if ($isShared && preg_match('/<v[^>]*>(\d+)<\/v>/', $inner, $vm)) {
                            $cells[] = $sharedStrings[(int)$vm[1]] ?? '';
                        } elseif (preg_match('/<v[^>]*>(.*?)<\/v>/', $inner, $vm)) {
                            $cells[] = $vm[1];
                        } else {
                            $cells[] = trim(strip_tags($inner));
                        }
                    }
                    if ($cells) $rowTexts[] = implode("\t", $cells);
                }
                $texts[] = implode("\n", $rowTexts);
            }
        }
        $zip->close();
        return $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8(implode("\n\n", $texts)));
    }

    /** 去除XML标签保留文本 */
    private function stripXmlTags(string $xml): string
    {
        return trim(strip_tags(preg_replace('/<a:t[^>]*>/', '', preg_replace('/<\/a:t>/', '', $xml))));
    }

    /**
     * @return array<int, string>
     */
    public function decodeKnowledgeFilePaths(string $storedValue): array
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '') {
            return [];
        }

        $decoded = json_decode($storedValue, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn ($path): bool => is_string($path) && trim($path) !== ''));
        }

        return [$storedValue];
    }

    /**
     * @param  array<int, string>  $relativePaths
     */
    public function cleanupKnowledgeFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $relativePath) {
            $this->deleteKnowledgeFilePath($relativePath);
        }
    }

    public function deleteKnowledgeFilePath(string $relativePath): void
    {
        $relativePath = $this->normalizeDeletableKnowledgePath($relativePath);
        if ($relativePath === null) {
            return;
        }

        if (Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    private function normalizeDeletableKnowledgePath(string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || preg_match('/^[A-Za-z]:\//', $relativePath) === 1
            || str_contains('/'.$relativePath.'/', '/../')
        ) {
            return null;
        }

        foreach (['knowledge-bases/', 'uploads/knowledge/', 'uploads/enterprise-knowledge/'] as $allowedPrefix) {
            if (str_starts_with($relativePath, $allowedPrefix)) {
                return $relativePath;
            }
        }

        return null;
    }

    public function convertUploadedTextToUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if (! $detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);

        return $converted === false ? $text : $converted;
    }

    public function normalizeKnowledgeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', (string) $text);

        return trim((string) $text);
    }

    public function extractDocxContent(string $absolutePath): string
    {
        if (! class_exists('ZipArchive')) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xmlContent) || $xmlContent === '') {
            return '';
        }

        $dom = new \DOMDocument;
        $loaded = @$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $loaded) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $parts = [];
        $nodes = $xpath->query('//w:t');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $value = trim((string) $node->textContent);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return $this->normalizeKnowledgeText(implode("\n", $parts));
    }
}
