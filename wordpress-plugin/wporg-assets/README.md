# WordPress.org directory assets

Images for the plugin's page on WordPress.org. They go in the SVN repository's top-level `assets/` folder
(next to `trunk/` and `tags/`), never inside the plugin zip.

| File | Shown as |
|---|---|
| `icon-128x128.png`, `icon-256x256.png` | Plugin icon (search results, plugin card) |
| `banner-772x250.png`, `banner-1544x500.png` | Header banner (normal and high-DPI) |
| `screenshot-1.png` | Settings page - API key, toggles, and running storage-saved total |
| `screenshot-2.png` | Bulk-offload progress for an existing Media Library |
| `screenshot-3.png` | Media Library list view showing offload status per file |

Screenshot numbers match the `== Screenshots ==` captions in the plugin's `readme.txt`.
`icon.html` and `banner.html` are the sources the icon and banners were rendered from (Playwright, exact pixel sizes).

After approval:

    svn co https://plugins.svn.wordpress.org/yourimageshare-media-offload yis-svn
    cp wporg-assets/*.png yis-svn/assets/
    cp -r yourimageshare-media-offload/* yis-svn/trunk/
    svn cp yis-svn/trunk yis-svn/tags/1.3.0     # after adding trunk
    cd yis-svn && svn add --force assets trunk tags && svn ci -m "1.3.0 + directory assets" --username <WordPress.org username>
