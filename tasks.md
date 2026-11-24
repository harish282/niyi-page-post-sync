Create a wordpress plugin to sync pages and posts using wordpress rest API between 2 wordpress setups

1. User will be able to add multiple receivers and there REST API endpoints with username and password
2. in pages and posts listing, user would be able to sync selected or single page/post to selected receiver(s), if multiple it would ask in popup to select receivers else if single receiver setup it create a new page if not exists else update the page there, keep genereated page/post id of receivers to update later if needed
3. since wordpress using ids for images, we need to fix that as well, so after upload images/media we need to update ids as well, we need to keep track those as well in case of update
