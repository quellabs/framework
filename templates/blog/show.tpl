{* templates/blog/show.tpl *}
{capture assign="content"}
    <h1>{$post->getTitle()}</h1>
    <p>{$post->getContent()}</p>
    <p><a href="/posts">&larr; Back to posts</a></p>
{/capture}

{include file="layout.tpl" content=$content}