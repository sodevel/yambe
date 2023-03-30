# YAMBE Hierarchical Breadcrumb

The YAMBE (Yet Another MediaWiki Breadcrumb Extension) extension allows to insert a chain of breadcrumbs into a wiki page
to ease up navigation in a structured way. In contrast to other extensions, this does not depend on categories or manually
specifying the complete navigation path, but by adding a parent relationship to the page.

## Installation

The extension has no external dependencies, just clone the repository into the `extension` directory of your MediaWiki
installation using the directory name `Yambe` for it. Load the extension by adding `wfLoadExtension( 'Yambe' );` to your
`LocalSettings.php` file.

## Configuration

The extension can be configured by `LocalSettings.php` using the following variables, the default values are shown below:

```php
// Fragment to split the URL with to find the page title, the part right of this fragment will be used. Usually either /w/index.php?title= or /wiki/.
$wgYambeURLsplit = '/w/index.php?title=';

// String to separate the breadcrumb elements with.
$wgYambeBCdelimiter = ' &gt; ';

// Maximum number of breadcrumb elements.
$wgYambeBCmaxCount = 5;

// Prefix to use if the maximum number of breadcrumb elements is exceeded.
$wgYambeBCoverflowPrefix = '[...]';

// If true, the breadcrumb element for the current page will be a link to it, otherwise it will be plain text.
$wgYambeBCselfLink = false;

// If true, the breadcrumbs will also be visible in the printable page variant, otherwise not.
$wgYambeBCprintable = false;
```

The breadcrumbs are rendered into a `div` element with the id `yambe`, this can be used for styling with CSS.

## Usage

### General

To define the parent page of a child page, the YAMBE tag needs to be added to the child page:

```xml
<yambe:breadcrumb self='Self Text'>Parent_Title|Parent Text</yambe:breadcrumb>
```

**NOTE**: All contents of the tag must be valid XML, this forbids the usage of raw `<`, `>` and `&` characters, use the corresponding
HTML entities `&lt;`, `&gt;` and `&amp;` instead.

This tag gets rendered as the breadcrumb chain to the child page, add it at the location where the breadcrumbs should be displayed.
Currently, only one such tag per page is supported.

The only required element is `Parent_Title`, the title of the parent page. If `Parent Text` is given, this will be used as name for the breadcrumb of that page,
otherwise the display name of the page will be used. To not specify `Parent Text`, don't add the pipe character `|`, or `Parent Text` will be defined as empty.
If `Self Text` is given, this will be used as name for the breadcrumb of the current page, otherwise the display name of the current page will be used.

For the root page, use an empty `Parent_Title`. To suppress the display of the breadcrumbs on the root page, specify an empty `Self Text`.
There can be more than one root page, each such page starts a new breadcrumb chain.

### New Page

To simplify the construction of breadcrumb chains, the YAMBE tag gets inserted automatically into the edit field of a new page if the page creation
was triggered from a page that already contains such a tag. This happens when a red link on such a page is clicked. If in such case the tag is missing
from the edit field, verify that `$wgYambeURLsplit` is correctly setup for your wiki.
