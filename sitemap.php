<?php
header('Content-Type: application/xml; charset=utf-8');

// Configuration
$baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$pagesDir = __DIR__ . '/pages';

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?php echo $baseUrl; ?>/</loc>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <?php
if (is_dir($pagesDir)) {
    $files = scandir($pagesDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
            continue;
        }
        if ($file === '404.php')
            continue; // Exclude 404 page

        $slug = pathinfo($file, PATHINFO_FILENAME);
        $url = $baseUrl . '/' . $slug;
        $modTime = date('c', filemtime($pagesDir . '/' . $file));
?>
    <url>
        <loc><?php echo $url; ?></loc>
        <lastmod><?php echo $modTime; ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
            <?php
    }
}
?>
</urlset>
