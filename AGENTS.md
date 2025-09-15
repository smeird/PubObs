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
16. The website uses the MQTT.js JavaScript client to subscribe to MQTT topics over WebSockets on port 8083.
17. Historical data resides in a MySQL table named `sensor_data` with columns `topic`, `timestamp`, and `value`.
18. Database credentials are read from the environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS`.
19. Historical weather data is now stored in a MySQL table named `obs_weather` with columns `dateTime`, `clouds`, `temp`, `wind`, `gust`, `rain`, `light`, `switch`, `safe`, `hum`, and `dewp`.
20. The index page displays current MQTT values in a responsive grid of Tailwind CSS cards.
21. The index page shows an indicator reflecting the MQTT connection status.
21. The index page shows a bar chart of nightly observable hours for the last 30 days using safe data from `obs_weather`.
22. If the MQTT.js library fails to load, the index page should display an MQTT unavailable status and avoid runtime errors.
22. Beneath the observable hours bar chart, the index page displays a live chart of clouds, light, and SQM values sourced from MQTT.
23. Historical pages default to the last week of data and provide controls to view any date range in the database.
24. The site uses `favicon.svg` as its favicon, linked from all pages.
25. The index page loads the MQTT.js library with multiple fallbacks and automatically reconnects with exponential backoff if the MQTT connection is lost.
26. Historical page queries were previously capped at a maximum span of seven days to prevent excessive data loads. (Superseded by item 38)
27. Historical pages provide a button to download data as CSV instead of displaying a table.
28. The site title is "Wheathampstead AstroPhotography Conditions".
29. `mqtt_config.json` topics can include a `green` threshold and `condition` (`above` or `below`) that turns the index page card border green when the incoming MQTT value meets the rule.
30. `mqtt_config.json` topics may define a `unit` string to specify the measurement unit for display.
31. The index page displays an icon before each MQTT topic name in its card.
32. Observable hours are calculated by summing time intervals where the `safe` field equals 1.
33. Safe data aggregation processes database rows sequentially to limit memory usage.

34. The site includes a `clear.php` page that charts monthly safe observing hours for a selected year and is linked from the index page.

35. Safe-hour charts include time from the last record to the current period end to account for ongoing clear conditions.

36. The index page displays a sky image updated via the MQTT topic `Observatory/skyimage` instead of a live sensor graph.

37. Historical charts use Highcharts' range selector to manage date ranges instead of manual date inputs.

38. Historical pages load all available data by default, removing the previous seven-day query limit.
39. Historical queries accept optional `start` or `end` parameters to filter results without requiring both.
40. Historical pages fetch data asynchronously via a JSON endpoint to handle large result sets without exhausting server memory.
