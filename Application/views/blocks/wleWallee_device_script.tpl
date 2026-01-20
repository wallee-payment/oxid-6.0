[{$smarty.block.parent}]

[{if $oViewConf && $oViewConf->getActiveClassName() == 'basket' && $WalleeDeviceScript}]
	<script src="[{$WalleeDeviceScript}]" async></script>
[{/if}]
