```markdown
# Kobold-TelegramBot 🤖💬

A feature-rich Telegram bot that brings AI-powered character interactions to your chats using KoboldAI, with full support for Character Card V2 (PNG/JSON) format.


## Features ✨

- 🖼️ **Character Card Support**
  - Upload V2 character cards (PNG+JSON or JSON only)
  - Built-in character gallery
  - Custom character creation/import

- 💬 **AI-Powered Chat**
  - Persistent conversation history
  - Context-aware responses
  - Multiple simultaneous character sessions

- 🛠️ **Advanced Features**
  - MarkdownV2 formatting support
  - Session management
  - Automatic retries & error handling
  - Detailed logging system

- 🔄 **Integration**
  - KoboldAI API compatibility
  - Telegram bot API integration
  - Webhook & long-polling support

## Installation 📦

### Prerequisites
- PHP 8.0+
- Composer
- Telegram bot token ([Get from @BotFather](https://core.telegram.org/bots#6-botfather))
- KoboldAI API endpoint

### Setup Steps

1. Clone the repository:
```bash
git clone https://github.com/altkriz/kobold-telegrambot.git
cd kobold-telegrambot
```

2. Install dependencies:
```bash
composer install
```

3. Set up directories:
```bash
mkdir -p cards/custom users
chmod 755 cards users
touch bot.log
```

4. Create `.env` file:
```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
KOBOLDAI_ENDPOINT=https://your-koboldai-instance/api
```

## Configuration ⚙️

### Environment Variables
| Variable | Description |
|----------|-------------|
| `TELEGRAM_BOT_TOKEN` | Telegram bot token from @BotFather |
| `KOBOLDAI_ENDPOINT` | KoboldAI API endpoint URL |

### Directory Structure
```
.
├── cards/          # Built-in character cards
│   └── custom/     # User-uploaded characters
├── users/          # User session data
├── bot.log         # System logs
└── .env            # Configuration
└── index.php       # Main File

```

## Usage 🚀

### Basic Commands
- `/start` - Initialize bot & show menu
- `Stop Session` - End current chat session
- `Switch Character` - Change active character
- `Upload Custom Card` - Add new character

### Character Interaction
1. Start the bot: `/start`
2. Choose a character from the menu
3. Begin chatting!

### Uploading Characters
1. Send command: `Upload Custom Card`
2. Send either:
   - Character Card PNG (with embedded JSON)
   - Character JSON file

## Examples 🎭

### Sample Conversation
```
User: Hello!
Bot: *Looks up* Oh, hello there! I didn't see you come in. 
     What brings you to the library today?

User: Just looking for a good book.
Bot: *Smiles warmly* Well you've come to the right place! 
     Any particular genre you're interested in?
```

### Supported Card Formats
- **PNG** (with embedded V2 character data)
- **JSON** (standard Character Card format)

## Troubleshooting 🐛

### Common Issues
1. **File Upload Fails**
   - Ensure files are sent as **documents** (not photos)
   - Verify PNG contains valid character data

2. **Bot Not Responding**
   - Check KoboldAI endpoint availability
   - Verify `.env` configuration
   - Inspect `bot.log` for errors

3. **Session Issues**
   - Use `Stop Session` command to reset
   - Check directory permissions for `users/`

### Viewing Logs
```bash
tail -f bot.log
```

## Contributing 🤝

We welcome contributions! Please follow these steps:
1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License 📄

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---
