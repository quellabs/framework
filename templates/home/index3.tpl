{literal}
<!DOCTYPE html>
<html lang="en">
<body>

<h1>WakaPAC</h1>

<!-- Basic Features Demo -->
<div id="demo">
    <textarea data-pac-bind="value: title"></textarea>
    <input data-pac-bind="value: title">
</div>

<script src="wakapac.js"></script>

<script>
    const waka = wakaPAC('#demo', {
        title: 'WakaPAC',
    });
</script>
</body>
</html>
{/literal}