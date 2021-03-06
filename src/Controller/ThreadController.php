<?php

namespace FOS\CommentBundle\Controller;

use FOS\CommentBundle\FormFactory\CommentableThreadFormFactoryInterface;
use FOS\CommentBundle\FormFactory\CommentFormFactoryInterface;
use FOS\CommentBundle\FormFactory\DeleteCommentFormFactoryInterface;
use FOS\CommentBundle\FormFactory\ThreadFormFactoryInterface;
use FOS\CommentBundle\FormFactory\VoteFormFactoryInterface;
use FOS\CommentBundle\Model\CommentInterface;
use FOS\CommentBundle\Model\CommentManagerInterface;
use FOS\CommentBundle\Model\ThreadInterface;
use FOS\CommentBundle\Model\ThreadManagerInterface;
use FOS\CommentBundle\Model\VoteManagerInterface;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\View\View;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Restful controller for the Threads.
 */
class ThreadController extends AbstractFOSRestController
{
    const VIEW_FLAT = 'flat';
    const VIEW_TREE = 'tree';

    /**
     * Presents the form to use to create a new Thread.
     */
    public function newThreadsAction(CommentFormFactoryInterface $formFactory): Response
    {
        $form = $formFactory->createForm();

        $view = $this->view([
            'data' => [
                'form' => $form->createView(),
            ],
            'template' => '@FOSComment/Thread/new.html.twig',
        ]);

        return $this->handleView($view);
    }

    /**
     * Gets the thread for a given id.
     */
    public function getThreadAction($id, ThreadManagerInterface $manager): Response
    {
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $view = $this->view(['thread' => $thread]);

        return $this->handleView($view);
    }

    /**
     * Gets the threads for the specified ids.
     */
    public function getThreadsAction(Request $request, ThreadManagerInterface $manager): Response
    {
        $ids = $request->query->get('ids');

        if (null === $ids) {
            throw new NotFoundHttpException('Cannot query threads without id\'s.');
        }

        $threads = $manager->findThreadsBy(['id' => $ids]);

        $view = $this->view(['threads' => $threads]);

        return $this->handleView($view);
    }

    /**
     * Creates a new Thread from the submitted data.
     */
    public function postThreadsAction(Request $request, ThreadManagerInterface $threadManager, ThreadFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->createThread();
        $form = $formFactory->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (null !== $threadManager->findThreadById($thread->getId())) {
                $this->onCreateThreadErrorDuplicate($form);
            }

            // Add the thread
            $threadManager->saveThread($thread);

            return $this->onCreateThreadSuccess($form);
        }

        return $this->handleView($this->onCreateThreadError($form));
    }

    /**
     * Get the edit form the open/close a thread.
     */
    public function editThreadCommentableAction(Request $request, $id, ThreadManagerInterface $manager, CommentableThreadFormFactoryInterface $formFactory)
    {
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $thread->setCommentable($request->query->get('value', 1));

        $form = $formFactory->createForm();
        $form->setData($thread);

        $view = $this->view([
            'data' => [
                'form' => $form->createView(),
                'id' => $id,
                'isCommentable' => $thread->isCommentable(),
            ],
            'template' => '@FOSComment/Thread/commentable.html.twig',
        ]);

        return $this->handleView($view);
    }

    /**
     * Edits the thread.
     */
    public function patchThreadCommentableAction(Request $request, $id, ThreadManagerInterface $manager, CommentableThreadFormFactoryInterface $formFactory)
    {
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $form = $formFactory->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $manager->saveThread($thread);

            return $this->handleView($this->onOpenThreadSuccess($form));
        }

        return $this->handleView($this->onOpenThreadError($form));
    }

    /**
     * Presents the form to use to create a new Comment for a Thread.
     */
    public function newThreadCommentsAction(Request $request, $id, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, CommentFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        $comment = $commentManager->createComment($thread);

        $parent = $this->getValidCommentParent($commentManager, $thread, $request->query->get('parentId'));

        $form = $formFactory->createForm();
        $form->setData($comment);

        $view = $this->view([
                'data' => [
                    'form' => $form->createView(),
                    'first' => 0 === $thread->getNumComments(),
                    'thread' => $thread,
                    'parent' => $parent,
                    'id' => $id,
                ],
                'template' => '@FOSComment/Thread/comment_new.html.twig',
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Get a comment of a thread.
     */
    public function getThreadCommentAction($id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);
        $parent = null;

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $ancestors = $comment->getAncestors();
        if (count($ancestors) > 0) {
            $parent = $this->getValidCommentParent($commentManager, $thread, $ancestors[count($ancestors) - 1]);
        }

        $view = $this->view([
                'data' => [
                    'comment' => $comment,
                    'thread' => $thread,
                    'parent' => $parent,
                    'depth' => $comment->getDepth(),
                ],
                'template' => '@FOSComment/Thread/comment.html.twig',
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Get the delete form for a comment.
     */
    public function removeThreadCommentAction(Request $request, $id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, DeleteCommentFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $formFactory->createForm();
        $comment->setState($request->query->get('value', $comment::STATE_DELETED));

        $form->setData($comment);

        $view = $this->view([
                'data' => [
                    'form' => $form->createView(),
                    'id' => $id,
                    'commentId' => $commentId,
                ],
                'template' => '@FOSComment/Thread/comment_remove.html.twig',
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Edits the comment state.
     */
    public function patchThreadCommentStateAction(Request $request, $id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, DeleteCommentFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $formFactory->createForm();
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                return $this->onRemoveThreadCommentSuccess($form, $id);
            }
        }

        return $this->handleView($this->onRemoveThreadCommentError($form, $id));
    }

    /**
     * Presents the form to use to edit a Comment for a Thread.
     */
    public function editThreadCommentAction($id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, CommentFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $formFactory->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);

        $view = $this->view([
                'data' => [
                    'form' => $form->createView(),
                    'comment' => $comment,
                ],
                'template' => '@FOSComment/Thread/comment_edit.html.twig',
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Edits a given comment.
     */
    public function putThreadCommentsAction(Request $request, $id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, CommentFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $formFactory->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                return $this->onEditCommentSuccess($form, $id, $comment->getParent());
            }
        }

        return $this->handleView($this->onEditCommentError($form, $id, $comment->getParent()));
    }

    /**
     * Get the comments of a thread. Creates a new thread if none exists.
     */
    public function getThreadCommentsAction(Request $request, $id, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, ValidatorInterface $validator)
    {
        $displayDepth = $request->query->get('displayDepth');
        $sorter = $request->query->get('sorter');
        $thread = $threadManager->findThreadById($id);

        // We're now sure it is no duplicate id, so create the thread
        if (null === $thread) {
            $permalink = $request->query->get('permalink');

            $thread = $threadManager->createThread();
            $thread->setId($id);
            $thread->setPermalink($permalink);

            // Validate the entity
            $errors = $validator->validate($thread, null, ['NewThread']);
            if (count($errors) > 0) {
                $view = $this->view([
                    'data' => [
                        'errors' => $errors,
                    ],
                    'template' => '@FOSComment/Thread/errors.html.twig',
                ], Response::HTTP_BAD_REQUEST);

                return $this->handleView($view);
            }

            // Decode the permalink for cleaner storage (it is encoded on the client side)
            $thread->setPermalink(urldecode($permalink));

            // Add the thread
            $threadManager->saveThread($thread);
        }

        $viewMode = $request->query->get('view', 'tree');
        switch ($viewMode) {
            case self::VIEW_FLAT:
                $comments = $commentManager->findCommentsByThread($thread, $displayDepth, $sorter);

                // We need nodes for the api to return a consistent response, not an array of comments
                $comments = array_map(function ($comment) {
                    return ['comment' => $comment, 'children' => []];
                },
                    $comments
                );
                break;
            case self::VIEW_TREE:
            default:
                $comments = $commentManager->findCommentTreeByThread($thread, $sorter, $displayDepth);
                break;
        }

        $view = $this->view([
                'data' => [
                    'comments' => $comments,
                    'displayDepth' => $displayDepth,
                    'sorter' => 'date',
                    'thread' => $thread,
                    'view' => $viewMode,
                ],
                'template' => '@FOSComment/Thread/comments.html.twig',
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Creates a new Comment for the Thread from the submitted data.
     */
    public function postThreadCommentsAction(Request $request, $id, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, CommentFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        if (!$thread->isCommentable()) {
            throw new AccessDeniedHttpException(sprintf('Thread "%s" is not commentable', $id));
        }

        $parent = $this->getValidCommentParent($commentManager, $thread, $request->query->get('parentId'));
        $comment = $commentManager->createComment($thread, $parent);

        $form = $formFactory->createForm(null, ['method' => 'POST']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                return $this->onCreateCommentSuccess($form, $id, $parent);
            }
        }

        return $this->handleView($this->onCreateCommentError($form, $id, $parent));
    }

    /**
     * Get the votes of a comment.
     */
    public function getThreadCommentVotesAction($id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $view = $this->view([
                'data' => [
                    'commentScore' => $comment->getScore(),
                ],
                'template' => '@FOSComment/Thread/comment_votes.html.twig',
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Presents the form to use to create a new Vote for a Comment.
     */
    public function newThreadCommentVotesAction(Request $request, $id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, VoteManagerInterface $voteManager, VoteFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $vote = $voteManager->createVote($comment);
        $vote->setValue($request->query->get('value', 1));

        $form = $formFactory->createForm();
        $form->setData($vote);

        $view = $this->view([
                'data' => [
                    'id' => $id,
                    'commentId' => $commentId,
                    'form' => $form->createView(),
                ],
                'template' => '@FOSComment/Thread/vote_new.html.twig',
            ]
        );

        return $this->handleView($view);
    }

    /**
     * Creates a new Vote for the Comment from the submitted data.
     */
    public function postThreadCommentVotesAction(Request $request, $id, $commentId, ThreadManagerInterface $threadManager, CommentManagerInterface $commentManager, VoteManagerInterface $voteManager, VoteFormFactoryInterface $formFactory)
    {
        $thread = $threadManager->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $vote = $voteManager->createVote($comment);

        $form = $formFactory->createForm();
        $form->setData($vote);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $voteManager->saveVote($vote);

            return $this->handleView($this->onCreateVoteSuccess($form, $id, $commentId));
        }

        return $this->handleView($this->onCreateVoteError($form, $id, $commentId));
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface    $form   Form with the error
     * @param string           $id     Id of the thread
     * @param CommentInterface $parent Optional comment parent
     *
     * @return Response
     */
    protected function onCreateCommentSuccess(FormInterface $form, $id, CommentInterface $parent = null)
    {
        return $this->forward('fos_comment.controller.thread::getThreadCommentAction', ['id' => $id, 'commentId' => $form->getData()->getId()]);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface    $form   Form with the error
     * @param string           $id     Id of the thread
     * @param CommentInterface $parent Optional comment parent
     *
     * @return View
     */
    protected function onCreateCommentError(FormInterface $form, $id, CommentInterface $parent = null)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $id,
                        'parent' => $parent,
                    ],
                    'template' => '@FOSComment/Thread/comment_new.html.twig',
                ]
            );

        return $view;
    }

    /**
     * Forwards the action to the thread view on a successful form submission.
     *
     * @param FormInterface $form
     *
     * @return Response
     */
    protected function onCreateThreadSuccess(FormInterface $form)
    {
        return $this->forward('fos_comment.controller.thread::getThreadAction', ['id' => $form->getData()->getId()]);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onCreateThreadError(FormInterface $form)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                    ],
                    'template' => '@FOSComment/Thread/new.html.twig',
                ]
            );

        return $view;
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the Thread creation fails due to a duplicate id.
     */
    protected function onCreateThreadErrorDuplicate(FormInterface $form)
    {
        return new Response(sprintf("Duplicate thread id '%s'.", $form->getData()->getId()), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Action executed when a vote was successfully created.
     *
     * @param FormInterface $form      Form with the error
     * @param string        $id        Id of the thread
     * @param mixed         $commentId Id of the comment
     *
     * @return View
     */
    protected function onCreateVoteSuccess(FormInterface $form, $id, $commentId)
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment_votes', ['id' => $id, 'commentId' => $commentId], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form      Form with the error
     * @param string        $id        Id of the thread
     * @param mixed         $commentId Id of the comment
     *
     * @return View
     */
    protected function onCreateVoteError(FormInterface $form, $id, $commentId)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'id' => $id,
                        'commentId' => $commentId,
                        'form' => $form,
                    ],
                    'template' => '@FOSComment/Thread/vote_new.html.twig',
                ]
            );

        return $view;
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface $form Form with the error
     * @param string        $id   Id of the thread
     *
     * @return View
     */
    protected function onEditCommentSuccess(FormInterface $form, $id)
    {
        return $this->forward('fos_comment.controller.thread::getThreadCommentAction', ['id' => $id, 'commentId' => $form->getData()->getId()]);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form Form with the error
     * @param string        $id   Id of the thread
     *
     * @return View
     */
    protected function onEditCommentError(FormInterface $form, $id)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'comment' => $form->getData(),
                    ],
                    'template' => '@FOSComment/Thread/comment_edit.html.twig',
                ]
            );

        return $view;
    }

    /**
     * Forwards the action to the open thread edit view on a successful form submission.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onOpenThreadSuccess(FormInterface $form)
    {
        return View::createRouteRedirect('fos_comment_edit_thread_commentable', ['id' => $form->getData()->getId(), 'value' => !$form->getData()->isCommentable()], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onOpenThreadError(FormInterface $form)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $form->getData()->getId(),
                        'isCommentable' => $form->getData()->isCommentable(),
                    ],
                    'template' => '@FOSComment/Thread/commentable.html.twig',
                ]
            );

        return $view;
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface $form Comment delete form
     * @param int           $id   Thread id
     *
     * @return Response
     */
    protected function onRemoveThreadCommentSuccess(FormInterface $form, $id)
    {
        return $this->redirectToRoute('fos_comment_get_thread_comment', ['id' => $id, 'commentId' => $form->getData()->getId()]);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form Comment delete form
     * @param int           $id   Thread id
     *
     * @return View
     */
    protected function onRemoveThreadCommentError(FormInterface $form, $id)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                    'data' => [
                        'form' => $form,
                        'id' => $id,
                        'commentId' => $form->getData()->getId(),
                        'value' => $form->getData()->getState(),
                    ],
                    'template' => '@FOSComment/Thread/comment_remove.html.twig',
                ]
            );

        return $view;
    }

    /**
     * Checks if a comment belongs to a thread. Returns the comment if it does.
     */
    private function getValidCommentParent(CommentManagerInterface $commentManager, ThreadInterface $thread, $commentId)
    {
        if (null !== $commentId) {
            $comment = $commentManager->findCommentById($commentId);
            if (!$comment) {
                throw new NotFoundHttpException(sprintf('Parent comment with identifier "%s" does not exist', $commentId));
            }

            if ($comment->getThread() !== $thread) {
                throw new NotFoundHttpException('Parent comment is not a comment of the given thread.');
            }

            return $comment;
        }

        return null;
    }
}
