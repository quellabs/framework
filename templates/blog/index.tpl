{* templates/blog/index.tpl *}
{capture assign="content"}
    <h1>Blog Posts</h1>

    {foreach $posts as $post}
        <div style="margin-bottom: 20px;">
            <h2><a href="/posts/{$post->getId()}">{$post->getTitle()}</a></h2>
            <p>{$post->getContent()|truncate:200}</p>
            <small>Posted on {$post->getCreatedAt()->format('F j, Y')}</small>
        </div>
    {/foreach}
{/capture}

{include file="layout.tpl" content=$content}