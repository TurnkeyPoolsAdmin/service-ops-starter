# Start Here

Welcome! A friend shared this tool with you. It is a working business dashboard: it books jobs, texts your customers automatically, tracks worker pay, and chases payments and reviews for you.

It was built for an auto glass company. You do not run an auto glass company — that is fine. An AI assistant (Claude) will interview you about **your** business and reshape the tool to fit it. You do not need to know how to code.

Follow these steps in order. Each step is small. Do one, then the next.

---

## Step 1 — Make a GitHub account (5 minutes)

GitHub is where your copy of the tool will live. Think of it as a safe home for the software.

1. Go to **github.com** and click **Sign up**.
2. Use your business email. Pick any username.
3. Verify your email when GitHub asks.

Done? Go to Step 2.

## Step 2 — Make your own copy of this tool (2 minutes)

You must have your own private copy. Never work in someone else's copy.

1. Open this project's page on GitHub (the page where you found this file).
2. Click the green **"Use this template"** button (top right), then **"Create a new repository"**.
   - If you do not see that button, click **Fork** instead.
3. Name it after your business, for example `smith-gutter-ops`.
4. Set it to **Private**. Click **Create**.

You now own a full copy. Nothing you do can affect anyone else's.

## Step 3 — Get Claude (10 minutes)

Claude is the AI assistant that will build and run this with you.

1. Go to **claude.ai** and create an account.
2. Subscribe to a paid plan. The **Pro plan (~$20/month)** is enough to start; the Max plan gives more room for the heavy build phase.
3. Go to **claude.ai/code** — this is "Claude Code," the version of Claude that can work on your project directly.
4. When it asks, connect your **GitHub account** and give it access to the repository you created in Step 2.

## Step 4 — Say the magic words (1 minute)

In Claude Code, open your repository and paste this exact message:

> Read the file SETUP_PROMPT.md in this project and follow it exactly. I am the business owner. I do not code. Set this tool up for my business, one step at a time.

That is it. Claude takes over from here.

## Step 5 — Answer the interview

Claude will ask you questions about your business — one at a time, in plain words. Things like: What services do you sell? How do workers get paid? What texts should customers get?

- Answer honestly and simply, like you are talking to a new office manager.
- If a question does not apply, say "not us" and Claude moves on.
- You can stop any time and come back. Your answers are saved in the project.

When the interview ends, Claude will tell you its plan in plain words and start building. This can take a few sessions. Claude will always tell you where you left off.

## Step 6 — Go live

When the tool is ready, Claude will walk you through hosting — putting the tool on the internet so you and your team can log in from anywhere. Claude gives you one small step at a time (create an account here, click this, paste that). Expect about an hour.

---

## What this costs per month (typical)

| Thing | Cost | Needed? |
|---|---|---|
| Claude subscription | $20–100 | Yes |
| Hosting (Laravel Cloud or Cloudways) | ~$20 | Yes |
| Texting number (OpenPhone or similar) | ~$15 | Yes, for customer texts |
| Telegram alerts | Free | Optional |
| Stripe card payments | Per-transaction fee only | Optional |
| Bank feed (Plaid) | ~$2 | Optional |

Roughly **$55–75/month** for the full setup. There is no license fee for this software.

## If you get stuck

Ask Claude. Seriously — paste the error or describe what you see, and ask "what do I do next?" in the same chat. That is what it is for.
