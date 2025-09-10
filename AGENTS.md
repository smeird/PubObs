# AGENTS

This file records design decisions and requirements for the PubObs website.

1. The site publicly displays observatory sensor data.
2. Live data is ingested via MQTT connections.
3. Historical data is served from a local MySQL database.
4. Web server: Apache2 with PHP 7.3.
5. Host environment: Ubuntu Linux on AWS.
6. Use Highcharts for all graphs.
7. Use Tabulator for all tables.
8. Support light and dark modes.
9. Use default styling from Tailwind CSS.
10. Database credentials are provided via environment variables in the Apache configuration.
11. The index page lists all live data sources and links to historical views.
12. The index page includes a live updating graph of sensor data.
13. The site should provide a modern look and feel.
14. README must include mermaid diagrams explaining the site and instructions on updating the website.

Design decisions added after this file should be appended here for future reference.

15. MQTT host and topic names are stored in `mqtt_config.json`.
16. The website uses the Paho JavaScript client to subscribe to MQTT topics over WebSockets on port 8083.
17. Historical data resides in a MySQL table named `sensor_data` with columns `topic`, `timestamp`, and `value`.
18. Database credentials are read from the environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`.
19. Historical weather data is now stored in a MySQL table named `obs_weather` with columns `dateTime`, `clouds`, `temp`, `wind`, `gust`, `rain`, `light`, `switch`, `safe`, `hum`, and `dewp`.
20. The index page displays current MQTT values in a responsive grid of Tailwind CSS cards.
21. The index page shows an indicator reflecting the MQTT connection status.


21. The index page shows a bar chart of nightly observable hours for the last 30 days using safe data from `obs_weather`.
22. If the Paho MQTT library fails to load, the index page should display an MQTT unavailable status and avoid runtime errors.

