import re

def update_css():
    filepath = 'resources/css/chat.css'
    with open(filepath, 'r') as f:
        content = f.read()

    old_css = """.sidebar-filters {
    display: flex;
    gap: 8px;
    padding: 0 15px 10px 15px;
    overflow-x: auto;
    scrollbar-width: none;
    border-bottom: 1px solid var(--border-line);
}"""

    new_css = """.sidebar-filters {
    display: flex;
    gap: 8px;
    padding: 10px 12px;
    overflow-x: auto;
    scrollbar-width: none;
    border-bottom: 1px solid var(--border-line);
    background: var(--panel-sidebar);
}"""

    if old_css in content:
        content = content.replace(old_css, new_css)
        with open(filepath, 'w') as f:
            f.write(content)
        print("Sidebar filters CSS updated.")
    else:
        print("Could not find old CSS block.")

update_css()
