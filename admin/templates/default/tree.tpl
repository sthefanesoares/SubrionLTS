<div id="parent_fieldzone" class="row">
	<label class="col col-lg-2 control-label">
		{lang key='category'} {lang key='field_required'}<br>
		<a href="#" class="categories-toggle" id="js-tree-toggler">{lang key='open_close'}</a>
	</label>
	<div class="col col-lg-4">
		<input type="text" id="js-category-label" value="{if $parent}{$parent.title|escape:'html'}{else}{lang key='field_category_id_annotation'}{/if}" disabled>
		<div id="js-tree" class="tree categories-tree"{if iaCore::ACTION_EDIT == $pageAction} style="display:none"{/if}></div>
		<input type="hidden" name="tree_id" id="input-tree" value="{if isset($item.parent_id)}{$item.parent_id}{else}{$parent.id}{/if}">
		{ia_add_js}
$(function()
{
	new IntelliTree(
	{
		url: '{$url}',
		onchange: intelli.fillUrlBox{if iaCore::ACTION_EDIT == $pageAction},
		nodeSelected: $('#input-tree').val(),
		nodeOpened: [{$item.parents}]{/if}
	});
});
		{/ia_add_js}
		{ia_add_media files='tree'}
	</div>
</div>