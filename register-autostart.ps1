$action = New-ScheduledTaskAction -Execute "E:\Qonhubgeo\GEOFlow-main\autostart.bat" -WorkingDirectory "E:\Qonhubgeo\GEOFlow-main"
$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "DouluoAI-AutoStart" -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force
Write-Host "已注册开机自启动: DouluoAI-AutoStart"
Write-Host "验证: 打开 任务计划程序 → 查找 DouluoAI-AutoStart"
