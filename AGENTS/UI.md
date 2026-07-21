
UI is the chat window with text input at the bottom.

To the left of the chat window I want to see the side-bar with the ability to select projects and button to upload the new one.

Project upload section should have:
- 2 fields name and description that are saved to database.
- ability to drag and drop folder or select it from you pc.

Uploaded projects should be put in local folder on the server and than evaluated in the background. Evaluation progress should be shown in UI. For evaluation you may create additional table in the database.

To evaluate uploaded project:

1. Recursively evaluate full text of each file with local LLM and put it in articles table.
    - title and description field should be generated automatically with local LLM.
    - embedding should be generated with local LLM.
    - link is formed with base_url of a project appending markdown name. During recursive folders evaluation append folder name as well as markdown name e.g. base_url.com/my_folder/my_file_name.md

2. Evaluate each file section and put it in articles_sections table.
    - sections split from each other by markdown headers # ## ### etc OR double newlines.
    - title and description field should be generated automatically with local LLM.
    - embedding should be generated with local LLM.
    - link to each section is a link to the header above it

