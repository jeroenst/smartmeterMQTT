#!/bin/sh
cp rsyslog/00-smartmeterMQTT.conf /etc/rsyslog.d
cp systemd/smartmeterMQTT.service /lib/systemd/system/
systemctl enable smartmeterMQTT
echo "You can now start the daemon with systemctl start smartmeterMQTT"
