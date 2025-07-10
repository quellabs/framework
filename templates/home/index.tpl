{* templates/home/index.tpl *}
{capture assign="content"}
    <h1>Welcome to Canvas Blog</h1>

    {foreach $posts as $post}
        <div style="margin-bottom: 20px;">
            <h3><a href="/posts/{$post.id}">{$post.title}</a></h3>
            <p>{$post.excerpt}</p>
        </div>
    {/foreach}
{/capture}

{include file="layout.tpl" content=$content}