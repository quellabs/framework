{capture assign="content"}
    <h1>Welcome to Canvas Blog</h1>

    {foreach $posts as $post}
        <div style="margin-bottom: 20px;">
            <h3><a href="/posts/{$post->getId()}">{$post->getTitle()}</a></h3>
            <p>{$post->getContent()}</p>
        </div>
    {/foreach}
{/capture}

{include file="layout.tpl" content=$content}