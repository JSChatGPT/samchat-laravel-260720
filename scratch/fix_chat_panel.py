import re

def update_css():
    filepath = 'resources/css/chat.css'
    with open(filepath, 'r') as f:
        content = f.read()

    # Fix chat-header background
    content = content.replace(
        '.chat-header {\n    height: 60px;\n    padding: 0 16px;\n    display: flex;\n    align-items: center;\n    background: var(--header-nav);',
        '.chat-header {\n    height: 60px;\n    padding: 0 16px;\n    display: flex;\n    align-items: center;\n    background: var(--panel-sidebar);'
    )

    # Fix chat-composer background
    content = content.replace(
        '.chat-composer {\n    min-height: 62px;\n    padding: 10px 16px;\n    background: var(--header-nav);',
        '.chat-composer {\n    min-height: 62px;\n    padding: 10px 16px;\n    background: var(--panel-sidebar);'
    )

    with open(filepath, 'w') as f:
        f.write(content)

def update_js():
    filepath = 'resources/js/chat.js'
    with open(filepath, 'r') as f:
        content = f.read()

    # Fix call-log background colors
    content = content.replace(
        "background: ${isMissed ? '#ef4444' : '#10b981'};",
        "background: ${isMissed ? 'var(--danger-error)' : 'var(--online-success)'};"
    )
    
    # Fix call-log subtitle text color to inherit from bubble
    content = content.replace(
        "<span style=\"font-size: 0.8rem; color: var(--text-muted);\">${meta.call_type === 'video' ? 'Video' : 'Voice'} call</span>",
        "<span style=\"font-size: 0.8rem; opacity: 0.85;\">${meta.call_type === 'video' ? 'Video' : 'Voice'} call</span>"
    )

    with open(filepath, 'w') as f:
        f.write(content)

update_css()
update_js()
print("Chat panel UI polish fixes applied successfully.")
