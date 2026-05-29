[b]Description:[/b]
The SMF Structured Data Markup mod adds Discussion Forum-style markup to public topics and boards via JSON-LD.

Google is requesting Structured Data Markup in order to enhance search results and to make relationships between URLs more clear.

This mod should help with the errors in Google Search Console reporting "...your forum pages are missing discussion forum structured data".

[b]Features:[/b]
 - Two types of Structured Data Markup are produced, both supported by Google Search:
    - DiscussionForumPosting
    - Breadcrumbs
 - All dates transformed to the required ISO 8601 date format
 - If forum guests can see avatars, poster/author avatars are included
 - If forum guests can see attachments, image attachments are included
 - Relationships between topics & boards & posts & replies are communicated via isPartOf & MainEntityOfPage properties
 - Statistics are communicated on views for topics
 - Statistics are communicated on likes for all posts & replies

There are multiple ways to approach adding Structured Data Markup to SMF.  The method used by this mod, JSON-LD, requires building a copy of your content in markup format.  Other formats such as RDFa and Microdata, nest special attributes throughout your existing code.  JSON-LD was used here because:
 - It is the least intrusive code-wise, and should work with any theme
 - It offers the ability to explicitly establish relationships between boards, topics, and posts, including active threads with many pages of replies
 - It is Google's preferred method

[b]Notes on Google Search Console:[/b]
 - Once structured data is provided, GSC will list many more errors & warnings under URL inspection.  You will need to monitor these closely going forward - there may be actions you need to take.
 - For example, if your forum does not allow guest visibility to user avatars or profiles, then this mod will not provide the author image or the author URL.  These are requested & optional in the structured data for forum posts, so warnings are issued.  No action is needed, these warnings may be ignored.
 - For example, if your forum does not allow guest visibility to user avatars or profiles, then this mod will not provide the author image or the author URL.  These are requested & optional in the structured data for forum posts, so warnings are .  No action is needed, these warnings may be ignored.
 - If your robots.txt restricted access to forum folders, this may interfere with the ability to depict avatars, icons, images, smileys, etc., in enhanced search results.  Tweaks to your robots.txt may be needed.
 - GSC may report issues with YouTube videos.  It's possible you need to adjust your site CORS settings to address.  It appears safe to ignore these errors - videos are still properly associated with the posts.

[b]Limitations:[/b]
 - Since this additional content doubles up a lot of data in the post, forums with large post sizes may have issues.  E.g., article-oriented forums sometimes have issues with post size or packet size limitations already...  This will definitely make that worse.

[b]Releases:[/b]
 - v1.0.0 Initial Commit
